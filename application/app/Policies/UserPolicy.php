<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manage-users');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manage-users');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('manage-users') && ! $user->is($target);
    }
}
