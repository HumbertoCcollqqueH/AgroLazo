<?php

namespace App\Services\Messaging;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProducerWelcome
{
    public function text(Producer $producer): string
    {
        $connection = app(EvolutionConnectionManager::class)->current();
        $name = preg_replace('/[\r\n]+/u', ' ', $producer->full_name);

        return '¡Hola, '.$name.'! Bienvenido a AgroLazo. Soy el asistente automático para registrar tus productos y cantidades.'
            ."\nTu ficha de productor ya está registrada; no necesitas volver a escribir tus datos."
            ."\n\n".($connection?->notice_text ?? config('messaging.notice_text'))
            ."\n\nPara continuar responde ACEPTO. Después podrás elegir opciones escribiendo un número o indicar producto y cantidad, por ejemplo: tengo 80 kilos de quinua."
            ."\nTu oferta se revisará antes de publicarse. Para atención de un coordinador escribe AYUDA; para no recibir más mensajes, BAJA.";
    }

    public function queue(User $actor, Producer $producer): Message
    {
        return DB::transaction(function () use ($actor, $producer): Message {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actor->id);
            abort_unless($actor->active, 403);
            Gate::forUser($actor)->authorize('manage-messaging');
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            $manager = app(EvolutionConnectionManager::class);
            $connection = $manager->current();
            if (! $connection?->isQr() || $connection->status !== 'connected' || ! $manager->automationEnabled() || ! $manager->noticeApproved()) {
                throw ValidationException::withMessages(['connection' => 'La bienvenida requiere WhatsApp QR conectado, respuestas activadas y aviso revisado.']);
            }
            if (! app(RegisteredContactPolicy::class)->producer($producer->phone_lookup)) {
                throw ValidationException::withMessages(['producer' => 'El productor no está habilitado para recibir mensajes.']);
            }
            $consent = $producer->consents()->where('purpose', 'whatsapp_messages')->latest('recorded_at')->latest('id')->first();
            if ($consent?->decision === 'declined') {
                throw ValidationException::withMessages(['producer' => 'El productor retiró su autorización. Debe solicitar ALTA desde su teléfono.']);
            }
            $account = $manager->account();
            $key = 'welcome:'.hash('sha256', $account.':'.$producer->id.':'.$producer->phone_lookup);
            if ($existing = Message::query()->where('deduplication_key', $key)->first()) {
                return $existing;
            }
            $conversation = Conversation::query()->firstOrCreate([
                'provider' => 'evolution', 'account' => $account, 'channel' => 'whatsapp', 'phone_lookup' => $producer->phone_lookup,
            ], ['phone' => $producer->phone, 'producer_id' => $producer->id, 'state' => 'NEW', 'consent' => 'pending', 'context' => []]);
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->state !== 'NEW' || $conversation->consent !== 'pending' || $conversation->human_assigned
                || ! app(RegisteredContactPolicy::class)->accepts($conversation)) {
                throw ValidationException::withMessages(['conversation' => 'Ya existe una conversación en curso. Continúa desde Mensajería; no se reinicia con otra bienvenida.']);
            }
            $conversation->update(['producer_id' => $producer->id, 'state' => 'WAITING_CONSENT', 'notice_version' => $connection->notice_version,
                'context' => ['notice_text' => $connection->notice_text]]);
            $message = $conversation->messages()->create([
                'provider' => 'evolution', 'account' => $account, 'direction' => 'outbound', 'deduplication_key' => $key,
                'body' => $this->text($producer), 'status' => 'queued', 'metadata' => ['is_onboarding' => true, 'welcome' => true, 'actor_id' => $actor->id],
            ]);
            if ($reason = app(MessageDelivery::class)->blockedReason($message)) {
                throw ValidationException::withMessages(['message' => 'No se puede enviar la bienvenida: '.$reason]);
            }
            OutboxEvent::enqueue('deliver:'.$message->id, 'deliver', ['message_id' => $message->id]);
            AuditLog::record($actor, 'conversation.welcome_queued', $conversation, ['message_id' => $message->id, 'producer_id' => $producer->id], 'explicit_operator_invitation');

            return $message;
        });
    }
}
