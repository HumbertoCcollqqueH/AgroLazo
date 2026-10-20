<?php

use App\Models\CoordinationThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('coordination.user.{userId}', function (User $user, string $userId): bool {
    return $user->active && (string) $user->id === $userId;
});

Broadcast::channel('coordination.thread.{threadId}', function (User $user, string $threadId): bool {
    if (! $user->active || ! ctype_digit($threadId)) {
        return false;
    }

    return CoordinationThread::query()->whereKey($threadId)
        ->when(! $user->hasPermission('view-operations'), fn ($query) => $query->where('user_id', $user->id))
        ->exists();
});
