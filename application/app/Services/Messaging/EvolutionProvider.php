<?php

namespace App\Services\Messaging;

use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EvolutionProvider
{
    public function send(Message $message): array
    {
        $connection = app(EvolutionConnectionManager::class);
        $template = $message->metadata['template'] ?? null;
        $payload = ['number' => ltrim($message->conversation->phone, '+'), 'text' => $message->body, 'linkPreview' => false];
        $operation = 'sendText';
        if ($template !== null) {
            $operation = 'sendTemplate';
            $payload = ['number' => ltrim($message->conversation->phone, '+'), 'name' => $template['name'], 'language' => $template['language'], 'components' => $template['components'] ?? []];
        }
        try {
            $response = Http::baseUrl(rtrim((string) config('messaging.evolution.url'), '/'))
                ->withHeaders(['apikey' => config('messaging.evolution.api_key')])->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()
                ->post('/message/'.$operation.'/'.rawurlencode($connection->instance()), $payload);
        } catch (ConnectionException) {
            return ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
        if ($response->status() === 429) {
            return ['status' => 'queued', 'error_code' => 'rate_limited'];
        }
        if ($response->serverError()) {
            return ['status' => 'uncertain', 'error_code' => 'provider_5xx'];
        }
        if (! $response->successful() || $response->json('error') || $response->json('response.error')) {
            return ['status' => 'failed', 'error_code' => 'provider_rejected'];
        }
        $id = $response->json('key.id');
        if (! is_string($id) || $id === '' || strlen($id) > 200 || (! $connection->isQr() && ! str_starts_with($id, 'wamid.'))) {
            return ['status' => 'uncertain', 'error_code' => 'missing_acceptance'];
        }

        return ['status' => 'accepted', 'external_id' => $id, 'error_code' => null];
    }
}
