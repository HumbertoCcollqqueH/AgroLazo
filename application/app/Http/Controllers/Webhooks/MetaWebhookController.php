<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use App\Services\Messaging\EvolutionConnectionManager;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('messaging.whatsapp.verify_token');
        $manager = app(EvolutionConnectionManager::class);
        abort_unless($manager->enabled() && ! $manager->isQr() && $token !== '', 503);
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $provided = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless($mode === 'subscribe' && is_string($provided) && hash_equals($token, $provided) && is_string($challenge) && strlen($challenge) <= 200, 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, MessageIngress $ingress): JsonResponse
    {
        $secret = (string) config('messaging.whatsapp.app_secret');
        $manager = app(EvolutionConnectionManager::class);
        abort_unless($manager->enabled() && ! $manager->isQr() && $secret !== '' && config('messaging.whatsapp.phone_number_id') && config('messaging.whatsapp.business_account_id'), 503);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 262144, 413);
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Hub-Signature-256')), 403);
        $data = json_decode($raw, true);
        abort_unless(is_array($data) && ($data['object'] ?? null) === 'whatsapp_business_account' && is_array($data['entry'] ?? null) && count($data['entry']) <= 100, 422);
        DB::transaction(function () use ($data, $ingress): void {
            foreach ($data['entry'] as $entry) {
                abort_unless(is_array($entry) && (string) ($entry['id'] ?? '') === (string) config('messaging.whatsapp.business_account_id') && is_array($entry['changes'] ?? null), 422);
                foreach ($entry['changes'] as $change) {
                    if (($change['field'] ?? '') !== 'messages') {
                        continue;
                    }
                    $value = $change['value'] ?? [];
                    $account = (string) config('messaging.whatsapp.phone_number_id');
                    abort_unless(is_array($value) && (string) ($value['metadata']['phone_number_id'] ?? '') === $account, 422);
                    $messages = $value['messages'] ?? [];
                    $statuses = $value['statuses'] ?? [];
                    abort_unless(is_array($messages) && is_array($statuses) && count($messages) + count($statuses) <= 100, 422);
                    foreach ($messages as $item) {
                        if (app(EvolutionConnectionManager::class)->current() && ! app(EvolutionConnectionManager::class)->automationEnabled()) {
                            continue;
                        }
                        abort_unless(is_array($item) && is_string($item['id'] ?? null) && str_starts_with($item['id'], 'wamid.') && is_string($item['from'] ?? null) && ctype_digit($item['from']) && ctype_digit((string) ($item['timestamp'] ?? '')), 422);
                        $text = ($item['type'] ?? '') === 'text' && is_string($item['text']['body'] ?? null) ? $item['text']['body'] : 'AYUDA';
                        $at = CarbonImmutable::createFromTimestamp((int) $item['timestamp'], 'UTC');
                        $message = $ingress->receive('evolution', $account, 'whatsapp', $item['id'], '+'.$item['from'], $text, $at, ['type' => ($item['type'] ?? '') === 'text' ? 'text' : 'unsupported']);
                        if ($message === null) {
                            continue;
                        }
                        $safeItem = ['id' => $item['id'], 'from' => $item['from'], 'timestamp' => (string) $item['timestamp'], 'type' => 'text', 'text' => ['body' => $message->body]];
                        $this->forward($entry['id'], $account, 'meta-in:'.$message->id, ['messages' => [$safeItem], 'contacts' => [['wa_id' => $item['from'], 'profile' => ['name' => 'Contacto AgroLazo']]]]);
                    }
                    foreach ($statuses as $status) {
                        abort_unless(is_array($status) && is_string($status['id'] ?? null) && str_starts_with($status['id'], 'wamid.') && ctype_digit((string) ($status['timestamp'] ?? '')), 422);
                        $state = $status['status'] ?? '';
                        if (! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                            continue;
                        }
                        $at = CarbonImmutable::createFromTimestamp((int) $status['timestamp'], 'UTC');
                        $ingress->receipt('evolution', $account, $status['id'], $state, $at);
                        $safe = ['id' => $status['id'], 'status' => $state, 'timestamp' => (string) $status['timestamp']];
                        if (is_string($status['recipient_id'] ?? null) && preg_match('/\A[1-9][0-9]{7,14}\z/', $status['recipient_id'])) {
                            $safe['recipient_id'] = $status['recipient_id'];
                            $this->forward($entry['id'], $account, 'meta-status:'.hash('sha256', json_encode($safe)), ['statuses' => [$safe]]);
                        }
                    }
                }
            }
        });

        return response()->json(['accepted' => true]);
    }

    private function forward(string $business, string $account, string $key, array $value): void
    {
        OutboxEvent::enqueue($key, 'forward_evolution', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => $business, 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $account], ...$value]]],
        ]]]);
    }
}
