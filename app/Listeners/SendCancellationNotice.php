<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\StaffBookingNotification;

class SendCancellationNotice
{
    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'staff', 'service']);

        $booking->customer->notify(new BookingCancelledNotification($booking));
        $booking->staff->notify(new StaffBookingNotification($booking, 'cancelled'));
    }
}
