<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Staff Member',
        };
    }

    /**
     * Admins manage the whole calendar; staff only their own bookings.
     */
    public function managesEveryone(): bool
    {
        return $this === self::Admin;
    }
}
