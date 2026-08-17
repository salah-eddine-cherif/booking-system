<?php

namespace App\Listeners;

use App\Events\DepositRequested;
use App\Notifications\DepositRequestedNotification;

class SendDepositRequest
{
    public function handle(DepositRequested $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'service']);

        $booking->customer->notify(new DepositRequestedNotification($booking));
    }
}
