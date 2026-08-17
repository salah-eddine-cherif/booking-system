<?php

namespace App\Listeners;

use App\Events\BookingRescheduled;
use App\Notifications\BookingRescheduledNotification;

class SendRescheduleNotice
{
    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'service']);

        $booking->customer->notify(
            new BookingRescheduledNotification($booking, $event->previousStartsAt)
        );
    }
}
