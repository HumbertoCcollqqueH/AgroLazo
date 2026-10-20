<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoordinationMessage extends Model
{
    protected $fillable = ['coordination_thread_id', 'user_id', 'body', 'submission_key'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CoordinationThread::class, 'coordination_thread_id');
    }
}
