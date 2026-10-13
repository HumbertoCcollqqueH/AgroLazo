<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'payment_id', 'actor_id', 'action', 'notes', 'details', 'occurred_at'])]
class PaymentReview extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['notes' => 'encrypted', 'details' => 'encrypted:array', 'occurred_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
