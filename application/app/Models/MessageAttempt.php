<?php

namespace App\Models;

use Database\Factories\MessageAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'token', 'outcome', 'error_code', 'started_at', 'finished_at'])]
class MessageAttempt extends Model
{
    /** @use HasFactory<MessageAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
