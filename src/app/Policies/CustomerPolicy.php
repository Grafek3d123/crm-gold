<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->is_active && ($user->isManager() || $user->isAdmin());
    }

    public function merge(User $user, Customer $customer): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function mergeInto(User $user, Customer $winner, Customer $loser): bool
    {
        return $user->is_active
            && $user->isAdmin()
            && ! $winner->is_merged
            && ! $loser->is_merged
            && ! $winner->is($loser);
    }
}
