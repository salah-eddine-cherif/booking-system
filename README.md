# Booking System

An appointment booking API built with Laravel 13. Services, per-staff availability,
timezone-correct slot generation, race-safe booking, Stripe deposits and queued
reminders.

The interesting part is not the CRUD — it is the availability engine and the
double-booking guard. Both are covered in detail below and pinned down by tests.

- **166 tests, 362 assertions**, run with Pest
- API-only; the booking UI is expected to be a separate front end
- SQLite out of the box, MySQL/PostgreSQL without changes

---

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

The seeder creates a small clinic with three staff across three timezones
(Lisbon, New York, Kolkata), three services, and a couple of real bookings made
through the booking service itself.

```
admin@example.com / password     (admin, manages everyone's calendar)
ana@example.com   / password     (staff, Europe/Lisbon)
```

Run the tests:

```bash
./vendor/bin/pest
```

Two scheduled commands do the background work — run `php artisan schedule:work`
locally, or wire the scheduler up to cron in production:

```bash
php artisan bookings:send-reminders   # every five minutes
php artisan bookings:release-holds    # every minute
php artisan queue:work                # notifications are queued
```

---

## The domain

| Table | What it holds |
| --- | --- |
| `users` | Staff and admins. Each has their own `timezone`. |
| `customers` | The people booking. Cashier-billable, and usually never log in. |
| `services` | Duration, buffers, slot increment, pricing, deposit and booking-window rules. |
| `service_user` | Which staff deliver which service, with an optional price override. |
| `availability_rules` | The recurring weekly pattern, as local wall-clock times. |
| `availability_exceptions` | Date-specific overrides: days off, long lunches, one-off shifts. |
| `bookings` | The appointments themselves. |

Staff and customers are separate tables on purpose. A staff member is someone
whose calendar gets booked and who may log in; a customer is a guest identified
by an email address. Forcing both through one `users` table means either
customers get login rows they never use, or staff get billing columns they never
need.

---

## Availability

`AvailabilityCalculator` turns rules, overrides and existing bookings into a list
of bookable start times:

1. **Expand the weekly rules** across every local date the requested window touches.
2. **Apply date overrides.** If a date has any "available" override, those windows
   *replace* the weekly rules for that date. Every "unavailable" override is then
   subtracted from what remains.
3. **Walk the slot grid.** For each contiguous working block, step from its start
   by the service's increment and keep the starts where the appointment fits and
   nothing already booked collides.

Steps 2 and 3 are plain interval arithmetic, which lives in two small value
objects rather than in the calculator:

- `TimeRange` — a half-open `[start, end)` instant range that knows how to
  overlap, intersect and subtract.
- `TimeRangeCollection` — a normalised set of ranges: always sorted, never
  overlapping, and with *touching* ranges fused into one.

Half-open ranges are what make "10:00–11:00 and 11:00–12:00 do not clash"
unambiguous. Fusing touching ranges is what makes a 90-minute service bookable at
11:00 when a staff member happens to have two back-to-back rules of 09:00–12:00
and 12:00–17:00.

### Timezones

Every instant in the database is UTC. The only place local wall-clock time exists
is `availability_rules` and `availability_exceptions`, which store times like
`09:00:00` interpreted in the *staff member's* timezone.

That split is deliberate. "Ana works 09:00–17:00 on Mondays" has to stay true on
both sides of a daylight-saving change — if those rules were stored as UTC
instants, her whole schedule would silently slide by an hour twice a year.

`WallClock` handles the conversion, including the two days a year when it is not
a function:

- **Spring forward** — 02:30 does not exist. It resolves to 03:30: the shift
  starts at the earliest moment it can.
- **Fall back** — 01:30 happens twice. The first (still-DST) occurrence wins.
- A 23-hour or 25-hour local day is measured as exactly one calendar day, not as
  "+24 hours".

An overnight 22:00–06:00 shift across a spring-forward is seven real hours, and
the engine reports it as seven. There is a test for that.

Callers ask for availability in calendar dates *in their own zone*
(`?from=2026-03-02&to=2026-03-02&timezone=America/New_York`) and get back both the
UTC instant and its rendering in that zone.

### The slot grid

Start times are a fixed grid anchored on the start of each working block, so a
09:20–12:00 shift with a 30-minute increment offers 09:20, 09:50, 10:20, 10:50.
The grid is additionally re-anchored at local midnight, which keeps it
independent of how wide a window the caller asked about — without that, an
always-available staff member's blocks would merge across days and the anchor
would drift with the query.

The same anchor logic backs both slot generation and validation, so the engine
can never offer a time that the booking service would then refuse.

### Buffers

A service can pad appointments with `buffer_before_minutes` and
`buffer_after_minutes`. Each booking stores both its customer-visible window
(`starts_at`/`ends_at`) and its calendar footprint (`blocked_starts_at`/
`blocked_ends_at`, buffers included).

Storing both is worth the redundancy: overlap checks stay a single indexed range
query, and later edits to a service's buffer settings never silently move an
existing booking's footprint.

Buffers are allowed to spill past the edge of the working day. They exist to keep
appointments apart, not to shorten the day.

---

## Double-booking

Checking availability and then inserting a row is a check-then-act race. Two
requests can both read "10:00 is free" before either writes.

`BookingService::book()` closes it by taking a row lock on the staff member
inside the transaction, then re-running the full availability check against
committed state:

```php
DB::transaction(function () use ($pending, $now) {
    // Every concurrent attempt against this calendar queues here.
    User::query()->whereKey($pending->staff->id)->lockForUpdate()->first();

    // Re-checked inside the lock: whatever was true when the customer loaded
    // the page is irrelevant, only committed state counts.
    $this->availability->assertBookable(/* … */);

    return $this->persist($pending, $now);
}, attempts: 3);
```

A row lock rather than a range lock or an exclusion constraint because it behaves
identically on MySQL, PostgreSQL and SQLite. It serialises writes per staff
member, which is exactly the right granularity: two staff members are still
booked in parallel.

Stripe is called *after* the transaction commits. An external HTTP request should
never be holding a database lock, and if the call fails the hold simply expires on
its own.

### Group sessions

`services.capacity` allows more than one booking in a slot. Sharing is only ever
permitted between bookings of the same service starting at the same instant —
that is what capacity means. Any other overlap is a hard conflict, so a group
class can never be booked on top of a one-to-one appointment.

---

## Deposits

A service can require no deposit, a fixed amount, or a percentage of its price.
When one is due:

1. The booking is created as `pending` with a `hold_expires_at` in the near
   future. The slot is genuinely held — it is invisible to everyone else.
2. A Stripe PaymentIntent is created via Cashier but deliberately *not* confirmed
   server-side. The client confirms it with Stripe Elements, which keeps SCA and
   3-D Secure handling where it belongs.
3. The **webhook** promotes the booking to `confirmed` — never the browser, which
   can always close or navigate away mid-flow.
4. If payment never lands, `bookings:release-holds` expires the booking and puts
   the slot back on sale.

All webhook handlers are idempotent, because Stripe retries.

Money is stored as integer minor units throughout and only formatted at the edges.

---

## API

### Public

| Method | Route | |
| --- | --- | --- |
| `GET` | `/api/v1/services` | Active catalogue with staff |
| `GET` | `/api/v1/services/{slug}` | One service |
| `GET` | `/api/v1/services/{slug}/availability` | `?from=&to=&timezone=&staff_id=` |
| `POST` | `/api/v1/bookings` | Create a booking |
| `GET` | `/api/v1/bookings/{reference}` | `?email=` |
| `POST` | `/api/v1/bookings/{reference}/cancel` | `{ email, reason }` |

Bookings are addressed by a quotable reference (`BK-K7QM3XPD`, from an alphabet
with no `0`/`O` or `1`/`I`). Because a reference is quotable it is also
guessable-ish, so reading or cancelling one also requires the email address that
made it.

```bash
curl -X POST localhost:8000/api/v1/bookings \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "service": "follow-up-session",
    "staff_id": 2,
    "starts_at": "2026-03-02T10:00:00Z",
    "timezone": "Europe/London",
    "customer": { "name": "Joana Silva", "email": "joana@example.com" }
  }'
```

`starts_at` carrying its own offset is taken as an absolute instant. A bare local
time is interpreted in the supplied `timezone`.

A taken slot returns **409**, not 422 — the request was well-formed, the world
moved. The body carries a machine-readable reason:

```json
{ "message": "That slot was taken while you were booking it (…).", "reason": "already_booked" }
```

Reasons: `already_booked`, `at_capacity`, `outside_working_hours`,
`not_on_slot_grid`, `too_soon`, `too_far_ahead`, `staff_cannot_perform`,
`service_inactive`.

### Staff and admin

Sanctum tokens from `POST /api/v1/auth/token`.

| Method | Route | |
| --- | --- | --- |
| `GET` | `/api/v1/admin/bookings` | Filter by `staff_id`, `status`, `from`, `to` |
| `PATCH` | `/api/v1/admin/bookings/{reference}` | Status and staff notes |
| `POST` | `/api/v1/admin/bookings/{reference}/reschedule` | |
| `DELETE` | `/api/v1/admin/bookings/{reference}` | Cancel, optionally refunding |
| `GET` | `/api/v1/admin/staff/{staff}/schedule` | Rules, exceptions, resolved hours |
| `POST` | `/api/v1/admin/staff/{staff}/rules` | |
| `POST` | `/api/v1/admin/staff/{staff}/exceptions` | |
| `GET/POST/PATCH/DELETE` | `/api/v1/admin/services` | Admin only |

Admins see the whole calendar; staff see only their own column, and only edit
their own schedule. Deleting a service that has bookings deactivates it instead,
so history stays readable.

---

## Notifications

Domain events (`BookingConfirmed`, `DepositRequested`, `BookingCancelled`,
`BookingRescheduled`) are dispatched by the booking service and picked up by
auto-discovered listeners, which send queued mail.

Customers are always addressed in the timezone they booked in; staff always in
their own. Reminders go out on a per-service lead time and are stamped
`reminder_sent_at` the moment they are queued, which is what makes the sweep
idempotent under overlapping runs.

---

## Layout

```
app/
├── Console/Commands/     SendBookingReminders, ReleaseExpiredHolds
├── Enums/                BookingStatus, PaymentStatus, DepositType, UserRole
├── Events/ Listeners/    Domain events → queued notifications
├── Exceptions/           SlotUnavailableException, with reason codes
├── Http/                 Controllers, form requests, API resources
├── Models/
├── Policies/
├── Services/
│   ├── Availability/     AvailabilityCalculator, TimeSlot
│   ├── Booking/          BookingService, PendingBooking
│   └── Payments/         DepositService
└── Support/              TimeRange, TimeRangeCollection, WallClock, Money
```

## Tests

```
tests/Unit/         TimeRange, TimeRangeCollection, WallClock, DepositType
tests/Feature/      AvailabilityCalculator, BookingService, the API,
                    reminders, Stripe webhooks
```

The availability tests use fixed dates around real clock changes — Lisbon's March
transition, New York's spring-forward and fall-back — rather than relative dates,
so a failure always means a real regression. Stripe is stubbed by a
`FakeDepositService` that moves bookings through the same payment states without
a network call.

## Notes and trade-offs

- **Not multi-tenant.** One organisation per installation. Adding a tenant column
  would touch every query in the availability engine, and the scope was
  deliberately kept to one calendar.
- **Recurring appointments** are not implemented. The schema would take a
  `recurrence_rule` on bookings, but expanding a series against availability is a
  substantial feature in its own right.
- **`Model::shouldBeStrict()`** is on outside production, so lazy loading, missing
  attributes and silently discarded mass-assignment all fail loudly in
  development and tests.
- **Postgres exclusion constraints** would give a database-level guarantee against
  overlapping bookings, which is stronger than the row lock used here. That was
  traded away to keep the same behaviour across all three supported drivers.
