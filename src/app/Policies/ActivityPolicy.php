<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function update(User $user, Activity $activity): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}