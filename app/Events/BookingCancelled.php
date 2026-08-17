<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Booking $booking) {}
}
