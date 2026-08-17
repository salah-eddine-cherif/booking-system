<?php

namespace App\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingRescheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
    ) {}
}
