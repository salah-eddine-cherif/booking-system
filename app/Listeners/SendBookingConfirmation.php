<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\StaffBookingNotification;

/**
 * Auto-discovered by Laravel from the handle() type hint.
 */
class SendBookingConfirmation
{
    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'staff', 'service']);

        $booking->customer->notify(new BookingConfirmedNotification($booking));
        $booking->staff->notify(new StaffBookingNotification($booking, 'booked'));
    }
}
