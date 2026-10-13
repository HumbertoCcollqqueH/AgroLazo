<?php

namespace App\Policies;

use App\Models\Producer;
use App\Models\User;

class ProducerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view-producers');
    }

    public function view(User $user, Producer $producer): bool
    {
        return $user->hasPermission('view-producers');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manage-producers');
    }

    public function update(User $user, Producer $producer): bool
    {
        return $user->hasPermission('manage-producers') && $producer->status !== 'archived';
    }

    public function verify(User $user, Producer $producer): bool
    {
        return $user->hasPermission('verify-producers') && $producer->status !== 'archived';
    }
}
