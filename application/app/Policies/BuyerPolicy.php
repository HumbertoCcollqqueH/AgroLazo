<?php

namespace App\Policies;

use App\Models\Buyer;
use App\Models\User;

class BuyerPolicy
{
    public function view(User $user, Buyer $buyer): bool
    {
        return $user->active && ($user->id === $buyer->user_id || $user->hasPermission('view-operations'));
    }

    public function update(User $user, Buyer $buyer): bool
    {
        return $user->active && $user->id === $buyer->user_id && $user->hasPermission('view-buyer-area');
    }

    public function approve(User $user, Buyer $buyer): bool
    {
        return $user->hasPermission('approve-buyers') && $user->id !== $buyer->user_id;
    }

    public function purchase(User $user, Buyer $buyer): bool
    {
        return $user->id === $buyer->user_id && $user->hasPermission('place-orders') && $buyer->status === 'approved';
    }
}
