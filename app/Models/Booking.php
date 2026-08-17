<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single appointment.
 *
 * `starts_at`/`ends_at` are what the customer booked; `blocked_starts_at`/
 * `blocked_ends_at` widen that by the service buffers and are what the calendar
 * and the double-booking guard actually reason about. All are stored in UTC.
 */
#[RouteKey('reference')]
#[Fillable([
    'service_id', 'user_id', 'customer_id', 'starts_at', 'ends_at',
    'blocked_starts_at', 'blocked_ends_at', 'duration_minutes', 'customer_timezone',
    'status', 'payment_status', 'price_amount', 'deposit_amount', 'currency',
    'stripe_payment_intent_id', 'hold_expires_at', 'customer_notes', 'staff_notes',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_starts_at' => 'immutable_datetime',
            'blocked_ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'price_amount' => 'integer',
            'deposit_amount' => 'integer',
            'status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->reference ??= static::generateReference();
        });
    }

    /**
     * Short, unambiguous code a customer can read down a phone line: the
     * alphabet drops the characters that get misheard or mistyped (0/O, 1/I).
     */
    public static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = strlen($alphabet) - 1;

        do {
            $code = 'BK-';

            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, $length)];
            }
        } while (static::where('reference', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The staff member delivering the appointment.
     *
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Bookings that still occupy the calendar.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function blocking(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::blockingValues());
    }

    /**
     * Bookings whose blocked range intersects [$start, $end).
     *
     * Half-open on both sides, so a booking that ends exactly when another
     * starts is not an overlap.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, DateTimeInterface $start, DateTimeInterface $end): void
    {
        $query->where('blocked_starts_at', '<', $end)
            ->where('blocked_ends_at', '>', $start);
    }

    /**
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function forStaff(Builder $query, User|int $staff): void
    {
        $query->where('user_id', $staff instanceof User ? $staff->id : $staff);
    }

    /**
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('starts_at', '>=', now());
    }

    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Customers lose the right to cancel once inside the service's notice window;
     * staff always keep it.
     */
    public function isWithinCancellationWindow(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $notice = $this->service->cancellation_notice_minutes;

        return $this->starts_at->subMinutes($notice)->isBefore($now);
    }

    /**
     * The appointment start rendered in the zone the customer booked in.
     */
    public function localStartsAt(?string $timezone = null): CarbonImmutable
    {
        return $this->starts_at->setTimezone($timezone ?? $this->customer_timezone);
    }

    public function localEndsAt(?string $timezone = null): CarbonImmutable
    {
        return $this->ends_at->setTimezone($timezone ?? $this->customer_timezone);
    }

    /**
     * True once a payment hold has lapsed without the deposit being paid.
     */
    public function holdHasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->status === BookingStatus::Pending
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isBefore($now ?? CarbonImmutable::now());
    }
}
