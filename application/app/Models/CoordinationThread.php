<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoordinationThread extends Model
{
    protected $fillable = ['user_id', 'subject', 'status', 'customer_seen_at', 'team_seen_at', 'customer_seen_message_id', 'team_seen_message_id'];

    protected function casts(): array
    {
        return ['customer_seen_at' => 'immutable_datetime', 'team_seen_at' => 'immutable_datetime', 'customer_seen_message_id' => 'integer', 'team_seen_message_id' => 'integer'];
    }

    public function unreadFor(User $user, bool $operator): bool
    {
        $last = $this->messages->first();
        if (! $last || $last->user_id === $user->id) {
            return false;
        }
        $cursor = $operator ? $this->team_seen_message_id : $this->customer_seen_message_id;
        $seen = $operator ? $this->team_seen_at : $this->customer_seen_at;

        return $cursor !== null ? $last->id > $cursor : (! $seen || $last->created_at->gt($seen));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CoordinationMessage::class);
    }
}
