<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_key', 'order_id', 'collection_event_id', 'producer_id', 'user_id', 'body', 'status', 'message_id', 'error_code', 'notification_uuid'])]
class OperationalNotice extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'message_id', 'error_code'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CollectionEvent::class, 'collection_event_id');
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
