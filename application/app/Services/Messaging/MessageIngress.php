<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\OutboxEvent;
use App\Models\Role;
use App\Services\ProducerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageIngress
{
    public function __construct(private ProducerIdentity $identity) {}

    public static function redact(string $body): string
    {
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        if (preg_match('/\A\s*([0-9]{4})-([0-9]{2})-([0-9]{2})\s*\z/', $body, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return trim($body);
        }

        return mb_substr(preg_replace('/(?<!\d)\d(?:[\s.-]?\d){7,}(?!\d)/u', '[dato personal omitido]', $body) ?? '', 0, 1600);
    }

    public function receive(string $provider, string $account, string $channel, string $externalId, string $phone, string $body, CarbonImmutable $at, array $metadata = [], bool $processAutomatically = true): ?Message
    {
        if (! in_array($provider, ['fake', 'evolution', 'twilio'], true) || ! in_array($channel, ['simulator', 'whatsapp', 'sms'], true) || strlen($externalId) > 200 || $externalId === '' || strlen($account) > 100 || $account === '') {
            throw ValidationException::withMessages(['message' => 'Origen de mensaje inválido.']);
        }
        $phone = $this->identity->normalizePhone($phone);

        return DB::transaction(function () use ($provider, $account, $channel, $externalId, $phone, $body, $at, $metadata, $processAutomatically): ?Message {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $lookup = $this->identity->lookup('phone', $phone);
            $producer = app(RegisteredContactPolicy::class)->producer($lookup);
            if (config('messaging.registered_contacts_only', true) && $producer === null) {
                return null;
            }
            $key = 'in:'.hash('sha256', $provider.':'.$account.':'.$externalId);
            if ($existing = Message::query()->where('deduplication_key', $key)->first()) {
                if ($existing->conversation->phone_lookup !== $lookup || $existing->conversation->channel !== $channel) {
                    throw ValidationException::withMessages(['message' => 'La referencia del mensaje corresponde a otro contacto.']);
                }

                return $existing;
            }
            $conversation = Conversation::query()->firstOrCreate(['provider' => $provider, 'account' => $account, 'channel' => $channel, 'phone_lookup' => $lookup], ['phone' => $phone, 'context' => []]);
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if (! app(RegisteredContactPolicy::class)->accepts($conversation)) {
                return null;
            }
            if ($conversation->producer_id === null && $producer !== null) {
                $conversation->update(['producer_id' => $producer->id]);
            }
            $at = $at->min(CarbonImmutable::now());
            if (! $conversation->last_inbound_at || $at->greaterThan($conversation->last_inbound_at)) {
                $conversation->update(['last_inbound_at' => $at]);
            }
            $message = $conversation->messages()->create([
                'direction' => 'inbound', 'provider' => $provider, 'account' => $account, 'external_id' => $externalId,
                'deduplication_key' => $key, 'body' => self::redact($body),
                'metadata' => $processAutomatically ? $metadata : [...$metadata, 'ignored_reason' => 'automation_disabled'],
                'received_at' => $at, 'status' => $processAutomatically ? 'received' : 'processed',
                'processed_at' => $processAutomatically ? null : now(),
            ]);
            if ($processAutomatically) {
                OutboxEvent::enqueue('process:'.$message->id, 'process', ['conversation_id' => $conversation->id]);
            }

            return $message;
        });
    }

    public function receipt(string $provider, string $account, string $externalId, string $status, CarbonImmutable $at): void
    {
        if (! in_array($status, ['accepted', 'sent', 'delivered', 'read', 'failed'], true) || strlen($externalId) > 200) {
            throw ValidationException::withMessages(['receipt' => 'Estado de entrega inválido.']);
        }
        DB::transaction(function () use ($provider, $account, $externalId, $status, $at): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            MessageReceipt::query()->firstOrCreate(['event_key' => hash('sha256', $provider.':'.$account.':'.$externalId.':'.$status.':'.$at->timestamp)], [
                'provider' => $provider, 'account' => $account, 'external_id' => $externalId, 'status' => $status, 'occurred_at' => $at,
            ]);
            if ($message = Message::query()->where(compact('provider', 'account'))->where('direction', 'outbound')->where('external_id', $externalId)->lockForUpdate()->first()) {
                $this->reconcile($message);
            }
        });
    }

    public function reconcile(Message $message): void
    {
        if (! $message->external_id) {
            return;
        }
        $rank = ['queued' => 0, 'sending' => 0, 'uncertain' => 0, 'accepted' => 1, 'sent' => 2, 'failed' => 2, 'delivered' => 3, 'read' => 4];
        $status = $message->status;
        foreach (MessageReceipt::query()->where('provider', $message->provider)->where('account', $message->account)->where('external_id', $message->external_id)->orderBy('occurred_at')->orderBy('id')->get() as $receipt) {
            if (($rank[$receipt->status] ?? 0) > ($rank[$status] ?? 0) || ($receipt->status === 'failed' && $status === 'sent')) {
                $status = $receipt->status;
            }
        }
        if ($status !== $message->status) {
            $message->update(['status' => $status]);
        }
    }
}
