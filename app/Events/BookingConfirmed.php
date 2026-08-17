<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The slot is locked in: deposit-free, or the deposit has landed. */
class BookingConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Booking $booking) {}
}
