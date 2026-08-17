<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The slot is held, and the customer still owes a deposit. */
class DepositRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Booking $booking) {}
}
