<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['provider', 'account', 'channel', 'phone', 'phone_lookup', 'producer_id', 'state', 'context', 'consent', 'notice_version', 'consented_at', 'human_assigned', 'last_inbound_at', 'last_processed_at'])]
#[Hidden(['phone', 'phone_lookup', 'context'])]
class Conversation extends Model
{
    public const STATE_LABELS = ['NEW' => 'Primer contacto', 'WAITING_CONSENT' => 'Esperando autorización', 'WAITING_NAME' => 'Esperando nombre', 'WAITING_PRODUCER_TYPE' => 'Esperando tipo de productor', 'WAITING_IDENTITY_VERIFICATION' => 'Revisión de identidad', 'READY' => 'Listo para registrar una oferta', 'OPTED_OUT' => 'Baja del canal', 'OFFER_PRODUCT' => 'Seleccionar producto', 'OFFER_VARIETY' => 'Seleccionar variedad', 'OFFER_QUALITY' => 'Indicar calidad', 'OFFER_QUANTITY' => 'Indicar cantidad', 'OFFER_LOCATION' => 'Indicar ubicación', 'OFFER_FROM' => 'Inicio de disponibilidad', 'OFFER_UNTIL' => 'Fin de disponibilidad', 'OFFER_REVIEW' => 'Confirmar el resumen', 'OFFER_EDIT' => 'Cambiar un dato de la oferta', 'PRICE_CLASSIFICATION' => 'Completar variedad para consultar precio', 'PRICE_REVIEW' => 'Confirmar precio y publicación'];

    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'context' => 'encrypted:array', 'human_assigned' => 'boolean', 'last_inbound_at' => 'immutable_datetime', 'last_processed_at' => 'immutable_datetime', 'consented_at' => 'immutable_datetime'];
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function maskedPhone(): string
    {
        return '•••• '.substr($this->phone, -4);
    }
}
