<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

/**
 * Puts slots back on sale when a deposit never arrived.
 *
 * Without this, an abandoned checkout would keep a slot pending forever and
 * quietly starve the calendar.
 */
class ReleaseExpiredHolds extends Command
{
    protected $signature = 'bookings:release-holds';

    protected $description = 'Expire pending bookings whose deposit hold has lapsed';

    public function handle(BookingService $bookings): int
    {
        $released = $bookings->releaseExpiredHolds();

        $this->info("Released {$released} expired hold(s).");

        return self::SUCCESS;
    }
}
