<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Messaging\EvolutionConnectionManager;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, EvolutionConnectionManager $manager, MessageIngress $ingress): JsonResponse
    {
        $secret = (string) config('messaging.evolution.webhook_secret');
        abort_unless($manager->enabled() && $secret !== '', 503);
        abort_unless(hash_equals($secret, (string) $request->header('X-Evolution-Secret')), 403);
        abort_if(strlen($request->getContent()) > 1200000, 413);
        $data = json_decode($request->getContent(), true);
        abort_unless(is_array($data) && ($data['instance'] ?? null) === $manager->instance() && in_array($data['event'] ?? '', ['qrcode.updated', 'messages.upsert', 'messages.update', 'send.message', 'connection.update'], true), 422);
        Cache::put('messaging:evolution:last_callback_at', now()->toIso8601String(), now()->addDay());

        if (! $manager->isQr()) {
            return response()->json(['accepted' => true, 'canonical_source' => 'signed_meta']);
        }
        $payload = $data['data'] ?? [];
        abort_unless(is_array($payload), 422);
        if ($data['event'] === 'qrcode.updated') {
            $manager->storeQr($data['instance'], $payload['qrcode']['base64'] ?? null);
        } elseif ($data['event'] === 'connection.update') {
            $manager->connectionEvent($data['instance'], is_string($payload['state'] ?? null) ? $payload['state'] : '');
        } elseif ($data['event'] === 'messages.upsert') {
            $this->receive($payload, $manager, $ingress);
        } elseif ($data['event'] === 'messages.update') {
            $status = match ($payload['status'] ?? '') {
                'SERVER_ACK' => 'sent', 'DELIVERY_ACK' => 'delivered', 'READ', 'PLAYED' => 'read', 'ERROR' => 'failed', default => null,
            };
            $id = $payload['keyId'] ?? null;
            if ($status !== null && is_string($id) && $id !== '' && strlen($id) <= 200 && ($payload['fromMe'] ?? false) === true) {
                $ingress->receipt('evolution', $manager->account(), $id, $status, CarbonImmutable::now());
            }
        }

        return response()->json(['accepted' => true, 'canonical_source' => 'evolution_qr']);
    }

    private function receive(array $payload, EvolutionConnectionManager $manager, MessageIngress $ingress): void
    {
        $key = $payload['key'] ?? [];
        if (! is_array($key) || ($key['fromMe'] ?? null) !== false) {
            return;
        }
        $id = $key['id'] ?? null;
        $timestamp = $payload['messageTimestamp'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 200 || ! ctype_digit((string) $timestamp) || (int) $timestamp < 1 || (int) $timestamp > now()->addMinutes(5)->timestamp) {
            return;
        }
        $at = CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC');
        if ($at->lessThan(now()->subDay())) {
            return;
        }
        $message = $payload['message'] ?? [];
        $text = $message['conversation'] ?? $message['extendedTextMessage']['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            return;
        }
        $jid = $manager->resolveSender($key);
        if ($jid === null || ! preg_match('/\A([1-9][0-9]{7,14})@s\.whatsapp\.net\z/', $jid, $phone)) {
            return;
        }
        $ingress->receive('evolution', $manager->account(), 'whatsapp', $id, '+'.$phone[1], $text, $at, ['type' => 'text'], $manager->automationEnabled());
    }
}
