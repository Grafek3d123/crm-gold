<?php

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function changeStatus(User $user, Inquiry $inquiry): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }
}
