<?php

namespace App\Models;

use Database\Factories\ProducerPayoutProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'recorded_by', 'version', 'submission_key', 'request_hash', 'holder_name', 'yape_phone', 'qr_path', 'qr_mime', 'qr_sha256', 'created_at'])]
#[Hidden(['submission_key', 'request_hash', 'holder_name', 'yape_phone', 'qr_path', 'qr_sha256'])]
class ProducerPayoutProfile extends Model
{
    /** @use HasFactory<ProducerPayoutProfileFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['holder_name' => 'encrypted', 'yape_phone' => 'encrypted', 'qr_path' => 'encrypted', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los datos de cobro anteriores se conservan. Registra una nueva versión.'));
        static::deleting(fn () => throw new LogicException('Los datos de cobro forman parte del historial de desembolsos.'));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
