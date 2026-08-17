<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Staff own their own working hours; admins can edit anyone's.
     */
    public function manageSchedule(User $user, User $staff): bool
    {
        return $user->isAdmin() || $user->id === $staff->id;
    }
}
