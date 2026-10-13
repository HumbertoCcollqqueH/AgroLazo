<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['conversation_id', 'direction', 'provider', 'account', 'external_id', 'deduplication_key', 'body', 'metadata', 'status', 'error_code', 'received_at', 'processed_at', 'lease_until', 'next_attempt_at'])]
#[Hidden(['body', 'metadata'])]
class Message extends Model
{
    public const STATUS_LABELS = ['received' => 'Recibido', 'processed' => 'Procesado', 'queued' => 'En cola', 'sending' => 'Enviando', 'accepted' => 'Aceptado por el proveedor', 'sent' => 'Enviado', 'delivered' => 'Entregado', 'read' => 'Leído', 'failed' => 'Fallido', 'uncertain' => 'Entrega por confirmar', 'blocked' => 'Requiere revisión', 'cancelled' => 'Cancelado'];

    public const ERROR_LABELS = ['live_disabled' => 'Los envíos reales están desactivados', 'notice_not_approved' => 'Falta aprobar el aviso del canal', 'consent_required' => 'Falta autorización del contacto', 'consent_revoked' => 'El contacto retiró su autorización', 'contact_changed' => 'El teléfono del productor cambió', 'whatsapp_not_configured' => 'Falta completar WhatsApp', 'sms_not_configured' => 'Falta completar SMS', 'sms_two_way_unverified' => 'Falta validar recepción y envío con el proveedor SMS', 'sms_segment_limit' => 'La respuesta supera el límite de segmentos SMS', 'approved_template_required' => 'Se requiere una plantilla aprobada fuera de las 24 horas', 'template_not_approved' => 'La plantilla cambió o no está aprobada', 'transport_uncertain' => 'No se confirmó el resultado; revisar antes de reenviar', 'worker_interrupted' => 'El procesamiento se interrumpió; revisar la entrega', 'missing_acceptance' => 'El proveedor no devolvió una referencia válida', 'provider_5xx' => 'El proveedor tuvo un error; la entrega requiere revisión', 'provider_rejected' => 'El proveedor rechazó la solicitud', 'rate_limited' => 'Se reintentará por límite del proveedor', 'retry_limit' => 'Se alcanzó el límite de reintentos'];

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'metadata' => 'encrypted:array', 'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class);
    }
}
