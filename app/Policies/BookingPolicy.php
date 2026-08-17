<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Admins see the whole calendar; staff see only their own.
 *
 * Customers are not represented here at all — they are guests, and their access
 * is proved by the booking reference plus a matching email address.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->canManageBooking($booking);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->canManageBooking($booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->canManageBooking($booking);
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $user->canManageBooking($booking);
    }
}
