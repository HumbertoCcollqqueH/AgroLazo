<?php

namespace App\Models;

use Database\Factories\ProducerConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'conversation_id', 'recorded_by', 'purpose', 'notice_version', 'channel', 'decision', 'recorded_at'])]
class ProducerConsent extends Model
{
    /** @use HasFactory<ProducerConsentFactory> */
    use HasFactory;

    public const PURPOSES = ['profile_contact' => 'Registro y contacto operativo', 'whatsapp_messages' => 'Mensajes por WhatsApp', 'sms_messages' => 'Mensajes por SMS', 'simulator_messages' => 'Simulación local', 'location_planning' => 'Uso de coordenadas para planificar acopio'];

    public const DECISIONS = ['granted' => 'Autorizado', 'declined' => 'No autorizado'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de consentimientos no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de consentimientos no se elimina.'));
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
