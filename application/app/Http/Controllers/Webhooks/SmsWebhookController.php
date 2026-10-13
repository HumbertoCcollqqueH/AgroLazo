<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

class SmsWebhookController extends Controller
{
    private function signed(Request $request, string $endpoint): array
    {
        $token = (string) config('messaging.twilio.auth_token');
        $url = (string) config('messaging.twilio.'.$endpoint.'_url');
        abort_unless(config('messaging.twilio.enabled') && $token !== '' && str_starts_with($url, 'https://') && config('messaging.twilio.account_sid'), 503);
        abort_if(strlen($request->getContent()) > 32768, 413);
        abort_unless(str_starts_with((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded') && ! $request->getQueryString(), 415);
        // Parse the original body so global trimming cannot alter the signed values.
        parse_str($request->getContent(), $params);
        abort_unless(is_array($params) && collect($params)->every(fn ($v): bool => is_string($v)), 422);
        abort_unless((new RequestValidator($token))->validate((string) $request->header('X-Twilio-Signature'), $url, $params), 403);
        abort_unless(($params['AccountSid'] ?? '') === config('messaging.twilio.account_sid') && preg_match('/\ASM[0-9a-fA-F]{32}\z/', $params['MessageSid'] ?? '') === 1, 422);

        return $params;
    }

    public function receive(Request $request, MessageIngress $ingress): Response
    {
        $data = $this->signed($request, 'inbound');
        abort_unless(config('messaging.twilio.two_way_verified'), 503);
        abort_unless(($data['To'] ?? '') === config('messaging.twilio.from') && is_string($data['From'] ?? null), 422);
        $body = ($data['NumMedia'] ?? '0') === '0' ? ($data['Body'] ?? '') : 'AYUDA';
        $ingress->receive('twilio', $data['AccountSid'], 'sms', $data['MessageSid'], $data['From'], $body, CarbonImmutable::now(), ['type' => 'text']);

        return response('<?xml version="1.0" encoding="UTF-8"?><Response/>', 200, ['Content-Type' => 'text/xml']);
    }

    public function status(Request $request, MessageIngress $ingress): Response
    {
        $data = $this->signed($request, 'status');
        $state = $data['MessageStatus'] ?? '';
        $state = in_array($state, ['failed', 'undelivered'], true) ? 'failed' : $state;
        if (in_array($state, ['sent', 'delivered', 'failed'], true)) {
            $ingress->receipt('twilio', $data['AccountSid'], $data['MessageSid'], $state, CarbonImmutable::now());
        }

        return response('', 204);
    }
}
