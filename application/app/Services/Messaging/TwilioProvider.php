<?php

namespace App\Services\Messaging;

use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TwilioProvider
{
    public function send(Message $message): array
    {
        $sid = (string) config('messaging.twilio.account_sid');
        try {
            $response = Http::withBasicAuth($sid, (string) config('messaging.twilio.auth_token'))->asForm()->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json', [
                    'From' => config('messaging.twilio.from'), 'To' => $message->conversation->phone, 'Body' => $message->body, 'StatusCallback' => config('messaging.twilio.status_url'),
                ]);
        } catch (ConnectionException) {
            return ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
        if ($response->status() === 429) {
            return ['status' => 'queued', 'error_code' => 'rate_limited'];
        }
        if ($response->serverError()) {
            return ['status' => 'uncertain', 'error_code' => 'provider_5xx'];
        }
        if (! $response->successful()) {
            return ['status' => 'failed', 'error_code' => 'provider_http_'.$response->status()];
        }
        $id = $response->json('sid');
        if (! is_string($id) || preg_match('/\ASM[0-9a-fA-F]{32}\z/', $id) !== 1 || $response->json('account_sid') !== $sid || $response->json('error_code')) {
            return ['status' => 'uncertain', 'error_code' => 'missing_acceptance'];
        }

        return ['status' => 'accepted', 'external_id' => $id, 'error_code' => null];
    }
}
