<?php

namespace App\Services\Availability;

use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Support\TimeRange;
use App\Support\TimeRangeCollection;
use App\Support\WallClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a staff member's weekly rules, date overrides and existing bookings into
 * a concrete list of bookable start times.
 *
 * The pipeline, in order:
 *
 *   1. Expand the weekly rules across every local date the window touches.
 *   2. Let date-specific "available" overrides replace those rules entirely.
 *   3. Subtract the "unavailable" overrides — days off, lunches, meetings.
 *   4. Walk a fixed grid across each contiguous block and keep the starts where
 *      the appointment fits and nothing already booked gets in the way.
 *
 * Only step 1 deals in local wall-clock time, which is the only place daylight
 * saving can bite. Everything downstream is UTC instants.
 */
class AvailabilityCalculator
{
    /**
     * Bookable start times for one staff member within a window.
     *
     * @return Collection<int, TimeSlot>
     */
    public function slotsFor(
        Service $service,
        User $staff,
        TimeRange $window,
        ?CarbonImmutable $now = null,
    ): Collection {
        $now ??= CarbonImmutable::now();

        $earliest = $now->addMinutes($service->min_notice_minutes);
        $latest = $now->addDays($service->max_advance_days);

        // The whole request sits outside the booking horizon.
        if ($window->end <= $earliest || $window->start >= $latest) {
            return collect();
        }

        // Availability is resolved over whole local days either side of the
        // request. Working out slots from a window clipped to the exact request
        // would move the grid anchor (see slotAnchor) and could offer a start
        // time that assertBookable would then refuse.
        $scope = $this->localDayScope($window, $staff->timezone);

        $workingHours = $this->workingHours($staff, $scope);
        $bookings = $this->blockingBookings($staff, $scope);

        $slots = collect();

        foreach ($workingHours as $block) {
            foreach ($this->localDaysOf($block, $staff->timezone) as $day) {
                $anchor = $block->start->max($day->start);
                $limit = $block->end->min($day->end);

                for ($cursor = $anchor; $cursor < $limit; $cursor = $cursor->addMinutes($service->slot_increment_minutes)) {
                    $appointment = TimeRange::fromDuration($cursor, $service->duration_minutes);

                    // The appointment must fit inside one contiguous block. It may
                    // run past midnight to do so, but not past the end of the shift.
                    if ($appointment->end > $block->end) {
                        break;
                    }

                    if (! $this->withinRequestedWindow($cursor, $window, $earliest, $latest)) {
                        continue;
                    }

                    $booked = $this->occupancy(
                        $service,
                        $this->blockedRangeFor($service, $cursor),
                        $cursor,
                        $bookings,
                    );

                    if ($booked === null || $booked >= $service->capacity) {
                        continue;
                    }

                    $slots->push(new TimeSlot(
                        startsAt: $cursor,
                        endsAt: $appointment->end,
                        staffId: $staff->id,
                        capacity: $service->capacity,
                        booked: $booked,
                    ));
                }
            }
        }

        return $slots->sortBy(fn (TimeSlot $slot) => $slot->startsAt->getTimestamp())->values();
    }

    /**
     * Bookable start times across every staff member who offers the service.
     *
     * @param  Collection<int, User>|null  $staff  Defaults to everyone assigned to the service.
     * @return Collection<int, TimeSlot>
     */
    public function slotsForService(
        Service $service,
        TimeRange $window,
        ?Collection $staff = null,
        ?CarbonImmutable $now = null,
    ): Collection {
        $staff ??= $service->staff()->bookable()->get();

        return $staff
            ->flatMap(fn (User $member) => $this->slotsFor($service, $member, $window, $now))
            ->sortBy(fn (TimeSlot $slot) => [$slot->startsAt->getTimestamp(), $slot->staffId])
            ->values();
    }

    /**
     * The staff member's actual working time inside $window, as UTC ranges.
     */
    public function workingHours(User $staff, TimeRange $window): TimeRangeCollection
    {
        $timezone = $staff->timezone;

        $staff->loadMissing(['availabilityRules', 'availabilityExceptions']);

        // Walk local dates with a day of padding either side, so overnight shifts
        // and large UTC offsets are not clipped off the ends.
        $date = $window->start->setTimezone($timezone)->subDay();
        $lastDate = $window->end->setTimezone($timezone)->addDay()->format('Y-m-d');

        $available = [];
        $unavailable = [];

        while ($date->format('Y-m-d') <= $lastDate) {
            $key = $date->format('Y-m-d');

            $exceptions = $staff->availabilityExceptions
                ->filter(fn (AvailabilityException $e) => $e->date->format('Y-m-d') === $key);

            $overrides = $exceptions->filter(
                fn (AvailabilityException $e) => $e->is_available && ! $e->coversWholeDay()
            );

            if ($overrides->isNotEmpty()) {
                // A one-off working day replaces the weekly pattern for this date.
                foreach ($overrides as $override) {
                    $available[] = $this->localWindow($key, $override->start_time, $override->end_time, $timezone);
                }
            } else {
                foreach ($staff->rulesForDate($date) as $rule) {
                    /** @var AvailabilityRule $rule */
                    $available[] = $this->localWindow($key, $rule->start_time, $rule->end_time, $timezone);
                }
            }

            foreach ($exceptions->where('is_available', false) as $blocked) {
                $unavailable[] = $blocked->coversWholeDay()
                    ? WallClock::wholeDay($key, $timezone)
                    : $this->localWindow($key, $blocked->start_time, $blocked->end_time, $timezone);
            }

            $date = $date->addDay();
        }

        return TimeRangeCollection::make($available)
            ->subtract($unavailable)
            ->clampTo($window);
    }

    /**
     * Can this exact start time be booked right now?
     *
     * Used to validate an incoming request and — crucially — to re-check the slot
     * inside the booking transaction once the staff calendar has been locked.
     *
     * @throws SlotUnavailableException
     */
    public function assertBookable(
        Service $service,
        User $staff,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $now = null,
        ?int $ignoreBookingId = null,
    ): void {
        $now ??= CarbonImmutable::now();
        $startsAt = $startsAt->utc();

        if (! $service->is_active) {
            throw SlotUnavailableException::serviceInactive();
        }

        if (! $service->staff()->whereKey($staff->id)->exists()) {
            throw SlotUnavailableException::staffCannotPerform();
        }

        if ($startsAt->isBefore($now->addMinutes($service->min_notice_minutes))) {
            throw SlotUnavailableException::tooSoon($service->min_notice_minutes);
        }

        if ($startsAt->isAfter($now->addDays($service->max_advance_days))) {
            throw SlotUnavailableException::tooFarAhead($service->max_advance_days);
        }

        $appointment = TimeRange::fromDuration($startsAt, $service->duration_minutes);

        $workingHours = $this->workingHours(
            $staff,
            $this->localDayScope($appointment, $staff->timezone),
        );

        if (! $workingHours->fullyContains($appointment)) {
            throw SlotUnavailableException::outsideWorkingHours($startsAt);
        }

        // Offered start times form a fixed grid, so a request for 09:07 is refused
        // even though the staff member is technically free then.
        if (! $this->sitsOnSlotGrid($service, $workingHours, $startsAt, $staff->timezone)) {
            throw SlotUnavailableException::notOnSlotGrid($startsAt, $service->slot_increment_minutes);
        }

        $blocked = $this->blockedRangeFor($service, $startsAt);

        $occupancy = $this->occupancy(
            $service,
            $blocked,
            $startsAt,
            $this->blockingBookings($staff, $blocked, $ignoreBookingId),
        );

        if ($occupancy === null) {
            throw SlotUnavailableException::alreadyBooked($startsAt);
        }

        if ($occupancy >= $service->capacity) {
            throw SlotUnavailableException::atCapacity($startsAt, $service->capacity);
        }
    }

    /**
     * The calendar footprint of an appointment: the appointment plus its buffers.
     *
     * Buffers may spill past the edge of the working day — they exist to keep
     * appointments apart, not to shorten the day.
     */
    public function blockedRangeFor(Service $service, CarbonImmutable $startsAt): TimeRange
    {
        return new TimeRange(
            $startsAt->subMinutes($service->buffer_before_minutes),
            $startsAt->addMinutes($service->duration_minutes + $service->buffer_after_minutes),
        );
    }

    /**
     * How many bookings already sit in this slot, or null if something occupies it
     * that can never be shared.
     *
     * Sharing is only ever allowed between bookings of the same group service
     * starting at the same instant — that is exactly what capacity means. Any
     * other overlap is a hard conflict.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function occupancy(
        Service $service,
        TimeRange $blocked,
        CarbonImmutable $startsAt,
        Collection $bookings,
    ): ?int {
        $shared = 0;

        foreach ($bookings as $booking) {
            $existing = new TimeRange($booking->blocked_starts_at, $booking->blocked_ends_at);

            if (! $blocked->overlaps($existing)) {
                continue;
            }

            $isSameSession = $service->capacity > 1
                && $booking->service_id === $service->id
                && $booking->starts_at->equalTo($startsAt);

            if (! $isSameSession) {
                return null;
            }

            $shared++;
        }

        return $shared;
    }

    /**
     * Bookings that still occupy the staff member's calendar within a range.
     *
     * @return Collection<int, Booking>
     */
    private function blockingBookings(User $staff, TimeRange $window, ?int $ignoreBookingId = null): Collection
    {
        return Booking::query()
            ->forStaff($staff)
            ->whereIn('status', BookingStatus::blockingValues())
            ->overlapping($window->start, $window->end)
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->get();
    }

    /**
     * Is $startsAt one of the start times this service would actually offer?
     */
    private function sitsOnSlotGrid(
        Service $service,
        TimeRangeCollection $workingHours,
        CarbonImmutable $startsAt,
        string $timezone,
    ): bool {
        foreach ($workingHours as $block) {
            if ($startsAt < $block->start || $startsAt >= $block->end) {
                continue;
            }

            $offset = (int) round($this->slotAnchor($block, $startsAt, $timezone)->diffInMinutes($startsAt));

            return $offset >= 0 && $offset % $service->slot_increment_minutes === 0;
        }

        return false;
    }

    /**
     * Where the slot grid starts for a given moment inside a working block.
     *
     * Normally that is the start of the shift, so a 09:00-17:00 day with a
     * 20-minute increment offers 09:00, 09:20, 09:40 and so on. The grid is
     * additionally re-anchored at local midnight, which keeps it independent of
     * how wide a window the caller happened to ask about — without that, an
     * always-available staff member's blocks would merge across days and the
     * anchor would drift with the query.
     */
    private function slotAnchor(TimeRange $block, CarbonImmutable $moment, string $timezone): CarbonImmutable
    {
        $localMidnight = WallClock::wholeDay(
            $moment->setTimezone($timezone)->format('Y-m-d'),
            $timezone,
        )->start;

        return $block->start->max($localMidnight);
    }

    /**
     * The local calendar days a block touches, as UTC ranges.
     *
     * @return array<int, TimeRange>
     */
    private function localDaysOf(TimeRange $block, string $timezone): array
    {
        $days = [];
        $date = $block->start->setTimezone($timezone)->format('Y-m-d');
        $lastDate = $block->end->setTimezone($timezone)->format('Y-m-d');

        while ($date <= $lastDate) {
            $days[] = WallClock::wholeDay($date, $timezone);
            $date = CarbonImmutable::parse($date)->addDay()->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Widen a window to whole local days, with a day of slack either side.
     */
    private function localDayScope(TimeRange $window, string $timezone): TimeRange
    {
        $from = $window->start->setTimezone($timezone)->subDay()->format('Y-m-d');
        $to = $window->end->setTimezone($timezone)->addDay()->format('Y-m-d');

        return new TimeRange(
            WallClock::wholeDay($from, $timezone)->start,
            WallClock::wholeDay($to, $timezone)->end,
        );
    }

    private function withinRequestedWindow(
        CarbonImmutable $cursor,
        TimeRange $window,
        CarbonImmutable $earliest,
        CarbonImmutable $latest,
    ): bool {
        return $cursor >= $window->start
            && $cursor < $window->end
            && $cursor >= $earliest
            && $cursor <= $latest;
    }

    /**
     * A local start/end pair on a local date, resolved to a UTC range.
     *
     * An end at or before the start means the window runs past midnight (a
     * 22:00-02:00 shift), so it lands on the following local date.
     */
    private function localWindow(string $date, string $start, string $end, string $timezone): TimeRange
    {
        $startsAt = WallClock::local($date, $start, $timezone);
        $endsAt = WallClock::local($date, $end, $timezone);

        if ($endsAt <= $startsAt) {
            $nextDate = CarbonImmutable::parse($date)->addDay()->format('Y-m-d');
            $endsAt = WallClock::local($nextDate, $end, $timezone);
        }

        return new TimeRange($startsAt, $endsAt);
    }
}
