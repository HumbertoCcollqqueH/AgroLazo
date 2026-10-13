<?php

namespace App\Services\Messaging;

use App\Jobs\SyncWhatsAppContact;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\Role;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EvolutionConnectionManager
{
    public function current(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->find(1);
    }

    public function isQr(): bool
    {
        return $this->current()?->isQr() ?? false;
    }

    public function instance(): string
    {
        return $this->current()?->instance_name ?? (string) config('messaging.evolution.instance');
    }

    public function account(): string
    {
        return $this->isQr() ? 'qr:'.$this->instance() : (string) config('messaging.whatsapp.phone_number_id');
    }

    public function enabled(): bool
    {
        return $this->current() !== null || (bool) config('messaging.whatsapp.enabled');
    }

    public function automationEnabled(): bool
    {
        return $this->current()?->automation_enabled ?? (bool) config('messaging.live_enabled');
    }

    public function noticeApproved(): bool
    {
        return $this->current()?->notice_approved ?? (bool) config('messaging.notice_approved');
    }

    public function resolveSender(array $key): ?string
    {
        $jid = $key['remoteJid'] ?? null;
        if (! is_string($jid)) {
            return null;
        }
        if (preg_match('/\A[1-9][0-9]{7,14}@s\.whatsapp\.net\z/', $jid)) {
            return $jid;
        }
        if (! preg_match('/\A[0-9]{5,20}@lid\z/', $jid)) {
            return null;
        }
        $cacheKey = 'whatsapp:identity:'.hash('sha256', $this->account().':'.$jid);
        $alternate = $key['remoteJidAlt'] ?? null;
        if (is_string($alternate) && preg_match('/\A[1-9][0-9]{7,14}@s\.whatsapp\.net\z/', $alternate)) {
            Cache::put($cacheKey, Crypt::encryptString($alternate), now()->addDay());

            return $alternate;
        }
        if ($cached = Cache::get($cacheKey)) {
            return Crypt::decryptString($cached);
        }
        if (! Cache::add($cacheKey.':lookup', true, now()->addMinute())) {
            return null;
        }
        try {
            $response = $this->client()->post('/chat/findMessages/'.rawurlencode($this->instance()), [
                'where' => ['key' => ['remoteJid' => $jid, 'remoteJidAlt' => $jid, 'fromMe' => true]], 'offset' => 50, 'page' => 1,
            ]);
            if (! $response->successful()) {
                return null;
            }
            $phones = collect($response->json('messages.records', []))->map(function ($record) use ($jid): ?string {
                $identity = $record['key'] ?? [];
                $primary = $identity['remoteJid'] ?? null;
                $alternate = $identity['remoteJidAlt'] ?? null;
                $phone = $primary === $jid ? $alternate : ($alternate === $jid ? $primary : null);

                return is_string($phone) && preg_match('/\A[1-9][0-9]{7,14}@s\.whatsapp\.net\z/', $phone) ? $phone : null;
            })->filter()->unique()->values();
            if ($phones->count() === 1) {
                Cache::put($cacheKey, Crypt::encryptString($phones[0]), now()->addDay());

                return $phones[0];
            }
        } catch (ConnectionException) {
            return null;
        }

        return null;
    }

    public function syncContact(int $producerId): void
    {
        $connection = $this->current();
        if (! $connection?->isQr() || $connection->status !== 'connected') {
            return;
        }
        $producer = Producer::query()->whereNull('seed_batch')->whereIn('status', ['pending_verification', 'verified'])->find($producerId);
        if ($producer === null) {
            return;
        }

        $response = $this->client()->post('/baileys/getUSyncDevices/'.rawurlencode($connection->instance_name), [
            'jids' => [ltrim($producer->phone, '+').'@s.whatsapp.net'],
            'useCache' => false,
            'ignoreZeroDevices' => false,
        ]);
        if (! $response->successful() || $response->json('error')) {
            throw new RuntimeException('whatsapp_contact_sync_failed');
        }
    }

    private function client(): PendingRequest
    {
        if (! filled(config('messaging.evolution.url')) || ! filled(config('messaging.evolution.api_key')) || ! filled(config('messaging.evolution.webhook_secret'))) {
            throw new RuntimeException('evolution_not_configured');
        }

        return Http::baseUrl(rtrim((string) config('messaging.evolution.url'), '/'))
            ->withHeaders(['apikey' => config('messaging.evolution.api_key')])
            ->acceptJson()->timeout(15)->connectTimeout(3)->withoutRedirecting();
    }

    public function prepare(WhatsAppConnection $connection): void
    {
        $client = $this->client();
        $instances = $client->get('/instance/fetchInstances');
        if (! $instances->successful() || ! is_array($instances->json())) {
            throw new RuntimeException('instance_lookup_failed');
        }
        $existing = collect($instances->json())->first(fn ($row): bool => ($row['name'] ?? $row['instance']['instanceName'] ?? null) === $connection->instance_name);
        if ($existing && ($existing['integration'] ?? $existing['instance']['integration'] ?? null) !== $connection->integration) {
            throw new RuntimeException('instance_integration_mismatch');
        }
        if (! $existing) {
            $payload = ['instanceName' => $connection->instance_name, 'integration' => $connection->integration, 'qrcode' => false,
                'groupsIgnore' => true, 'syncFullHistory' => false, 'readMessages' => false, 'readStatus' => false, 'alwaysOnline' => false];
            if (! $connection->isQr()) {
                foreach (['access_token', 'phone_number_id', 'business_account_id', 'app_secret', 'verify_token', 'public_url'] as $key) {
                    if (! filled(config('messaging.whatsapp.'.$key))) {
                        throw new RuntimeException('meta_not_configured');
                    }
                }
                $payload += ['token' => config('messaging.whatsapp.access_token'), 'number' => config('messaging.whatsapp.phone_number_id'), 'businessId' => config('messaging.whatsapp.business_account_id')];
            }
            $created = $client->post('/instance/create', $payload);
            if (! $created->successful() || $created->json('error')) {
                throw new RuntimeException('instance_creation_uncertain');
            }
        }
        $callback = $client->post('/webhook/set/'.rawurlencode($connection->instance_name), ['webhook' => [
            'enabled' => true,
            'url' => config('messaging.evolution.callback_url', 'http://nginx/api/v1/webhooks/whatsapp/evolution'),
            'headers' => ['X-Evolution-Secret' => config('messaging.evolution.webhook_secret'), 'Host' => parse_url((string) config('app.url'), PHP_URL_HOST)],
            'webhookByEvents' => false, 'webhookBase64' => false,
            'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE'],
        ]]);
        if (! $callback->successful() || $callback->json('error')) {
            throw new RuntimeException('callback_setup_failed');
        }
        if ($connection->isQr()) {
            $result = $client->get('/instance/connect/'.rawurlencode($connection->instance_name));
            if (! $result->successful() || $result->json('error')) {
                throw new RuntimeException('qr_request_failed');
            }
            if ($result->json('instance.state') === 'open') {
                $this->connectionEvent($connection->instance_name, 'open');
            } else {
                $this->storeQr($connection->instance_name, $result->json('base64') ?? $result->json('qrcode.base64'), $connection->revision);
                WhatsAppConnection::query()->whereKey(1)->where('revision', $connection->revision)->where('status', 'preparing')
                    ->update(['status' => 'awaiting_scan', 'last_error' => null]);
            }
        } else {
            $connection->update(['status' => 'configured', 'last_error' => null]);
        }
    }

    public function storeQr(string $instance, mixed $value, ?int $revision = null): void
    {
        $connection = $this->current();
        if ($connection === null || ! $connection->isQr() || $connection->instance_name !== $instance
            || ($revision !== null && $revision !== $connection->revision) || ! is_string($value)
            || strlen($value) > 1048576 || ! str_starts_with($value, 'data:image/png;base64,')) {
            return;
        }
        $bytes = base64_decode(substr($value, 22), true);
        if (! is_string($bytes) || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return;
        }
        $updated = WhatsAppConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)
            ->whereIn('status', ['preparing', 'awaiting_scan'])->update(['status' => 'awaiting_scan', 'last_error' => null]);
        if ($updated) {
            Cache::put('whatsapp:qr:'.$connection->revision, Crypt::encryptString($value), now()->addSeconds(30));
        }
    }

    public function connectionEvent(string $instance, string $state): void
    {
        $status = match ($state) {
            'open' => 'connected', 'close', 'closed', 'refused' => 'disconnected',
            'connecting' => 'awaiting_scan', default => null,
        };
        if ($status === null) {
            return;
        }
        DB::transaction(function () use ($instance, $state, $status): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $connection = WhatsAppConnection::query()->lockForUpdate()->find(1);
            if ($connection === null || $connection->instance_name !== $instance
                || ($state === 'connecting' && $connection->status === 'connected')) {
                return;
            }
            $wasConnected = $connection->status === 'connected';
            $connection->update(['status' => $status, 'last_error' => null]);
            if ($state !== 'connecting') {
                Cache::forget('whatsapp:qr:'.$connection->revision);
            }
            if ($state === 'open' && $connection->isQr()) {
                $this->recoverDisconnectedMessages($connection);
                if (! $wasConnected && config('queue.default') === 'redis') {
                    Producer::query()->whereIn('status', ['pending_verification', 'verified'])->select('id')->each(function (Producer $producer): void {
                        SyncWhatsAppContact::dispatch($producer->id)->afterCommit();
                    });
                }
            }
        });
    }

    private function recoverDisconnectedMessages(WhatsAppConnection $connection): void
    {
        $messages = Message::query()->where('provider', 'evolution')->where('account', 'qr:'.$connection->instance_name)
            ->where('direction', 'outbound')->where('status', 'blocked')->where('error_code', 'whatsapp_disconnected')
            ->whereNull('external_id')->whereNull('lease_until')
            ->whereDoesntHave('attempts', fn ($query) => $query->where('outcome', '!=', 'queued')
                ->orWhereNull('error_code')->orWhere('error_code', '!=', 'rate_limited'))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($messages as $message) {
            $message->update(['status' => 'queued', 'error_code' => null, 'next_attempt_at' => now()]);
            OutboxEvent::enqueue('reconnect:'.$message->id.':'.Str::uuid(), 'deliver', ['message_id' => $message->id]);
        }
    }

    public function status(): array
    {
        $connection = $this->current();
        if ($connection === null) {
            return ['status' => 'not_configured', 'qr' => null, 'automation_enabled' => false];
        }
        $encrypted = $connection->isQr() && $connection->status === 'awaiting_scan' ? Cache::get('whatsapp:qr:'.$connection->revision) : null;

        return ['status' => $connection->status, 'qr' => is_string($encrypted) ? Crypt::decryptString($encrypted) : null,
            'automation_enabled' => $connection->automation_enabled, 'notice_approved' => $connection->notice_approved,
            'last_callback_at' => Cache::get('messaging:evolution:last_callback_at'), 'error' => $connection->last_error];
    }
}
