<?php

namespace App\Models;

use Database\Factories\MessageReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['provider', 'account', 'external_id', 'status', 'event_key', 'occurred_at'])]
class MessageReceipt extends Model
{
    /** @use HasFactory<MessageReceiptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los recibos de entrega son permanentes.'));
        static::deleting(fn () => throw new LogicException('Los recibos de entrega son permanentes.'));
    }
}
