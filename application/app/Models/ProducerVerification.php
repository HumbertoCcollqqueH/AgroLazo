<?php

namespace App\Models;

use Database\Factories\ProducerVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'verified_by', 'result', 'method', 'notes', 'verified_at'])]
#[Hidden(['notes'])]
class ProducerVerification extends Model
{
    /** @use HasFactory<ProducerVerificationFactory> */
    use HasFactory;

    public const METHODS = [
        'manual_document' => 'Revisión manual de documento',
        'field_visit' => 'Visita de campo',
        'assisted_call' => 'Llamada asistida',
        'administrative' => 'Decisión administrativa',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['notes' => 'encrypted', 'verified_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de verificación no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de verificación no se elimina.'));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
