<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\OperationalNotice;
use App\Models\OutboxEvent;
use App\Models\ProducerConsent;
use App\Models\Role;
use App\Services\OperationalNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageDelivery
{
    public function deliver(int $messageId): void
    {
        $claim = DB::transaction(function () use ($messageId): ?array {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $message = Message::query()->with('conversation')->lockForUpdate()->findOrFail($messageId);
            if ($message->direction !== 'outbound') {
                return null;
            }
            if ($message->status === 'sending' && $message->lease_until?->isPast()) {
                $message->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'lease_until' => null]);
                $message->attempts()->where('outcome', 'sending')->update(['outcome' => 'uncertain', 'error_code' => 'worker_interrupted', 'finished_at' => now()]);

                return null;
            }
            if ($message->status !== 'queued' || $message->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($reason = $this->blockedReason($message)) {
                $message->update(['status' => 'blocked', 'error_code' => $reason]);
                if ($reason === 'live_disabled' && ($message->metadata['is_onboarding'] ?? false)
                    && $message->conversation->consent === 'pending' && $message->conversation->state === 'WAITING_CONSENT') {
                    $message->conversation->update(['state' => 'NEW', 'context' => [], 'notice_version' => null]);
                }

                return null;
            }
            $token = (string) Str::uuid();
            $message->update(['status' => 'sending', 'lease_until' => now()->addMinute()]);
            $attempt = $message->attempts()->create(['token' => $token, 'outcome' => 'sending', 'started_at' => now()]);

            return [$message, $attempt->id, $token];
        });
        if ($claim === null) {
            return;
        }
        [$message, $attemptId, $token] = $claim;
        $result = match ($message->provider) {
            'fake' => ['status' => 'accepted', 'external_id' => 'fake:'.$message->id, 'error_code' => null],
            'evolution' => app(EvolutionProvider::class)->send($message),
            'twilio' => app(TwilioProvider::class)->send($message),
        };
        DB::transaction(function () use ($message, $attemptId, $token, $result): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $current = Message::query()->lockForUpdate()->findOrFail($message->id);
            $attempt = MessageAttempt::query()->findOrFail($attemptId);
            if ($attempt->token !== $token || $attempt->outcome !== 'sending') {
                return;
            }
            $attempt->update(['outcome' => $result['status'], 'error_code' => $result['error_code'] ?? null, 'finished_at' => now()]);
            $current->fill([...$result, 'lease_until' => null]);
            if ($result['status'] === 'queued') {
                if ($current->attempts()->count() >= 3) {
                    $current->fill(['status' => 'failed', 'error_code' => 'retry_limit']);
                } else {
                    $current->next_attempt_at = now()->addMinute();
                    $event = OutboxEvent::enqueue('retry:'.$attemptId, 'deliver', ['message_id' => $current->id]);
                    $event->update(['available_at' => $current->next_attempt_at]);
                }
            }
            $current->save();
            app(MessageIngress::class)->reconcile($current);
        });
    }

    public function blockedReason(Message $message): ?string
    {
        if (config('platform.internal_communication') && $message->provider !== 'fake') {
            return 'internal_channel_only';
        }
        $c = $message->conversation;
        if ($c->producer?->seed_batch !== null && $message->provider !== 'fake') {
            return 'generated_contact';
        }
        if (! app(RegisteredContactPolicy::class)->accepts($c)) {
            return 'unregistered_contact';
        }
        if ($c->consent === 'declined' && ! ($message->metadata['is_opt_out_confirmation'] ?? false)) {
            return 'consent_revoked';
        }
        if ($c->consent === 'pending' && ! ($message->metadata['is_onboarding'] ?? false)) {
            return 'consent_required';
        }
        if ($c->producer_id && $c->producer?->phone_lookup !== $c->phone_lookup) {
            return 'contact_changed';
        }
        if (isset($message->metadata['notice_id'])) {
            $notice = OperationalNotice::query()->with(['order', 'event'])->find($message->metadata['notice_id']);
            if (! $notice || $notice->producer_id !== $c->producer_id || $notice->message_id !== $message->id || $notice->status === 'cancelled') {
                return 'operational_notice_changed';
            }
            $allowedTest = $notice->order->synthetic && $message->provider === 'evolution'
                && OperationalNotifier::testWhatsAppEnabled()
                && ($message->metadata['test_whatsapp'] ?? false) === true
                && (! config('commerce.show_test_labels') || (str_starts_with($message->body, '[PRUEBA SINTÉTICA]') && str_contains($message->body, 'No prepares ni traslades producto')));
            if ($notice->order->synthetic !== ($message->provider === 'fake') && ! $allowedTest) {
                return 'operational_channel_mismatch';
            }
            if (str_starts_with($notice->event_key, 'order-confirmed:') && in_array($notice->order->status, ['cancelled', 'closed'], true)) {
                return 'order_changed';
            }
            if ($notice->collection_event_id && $notice->event?->status !== 'scheduled' && ! str_starts_with($notice->event_key, 'collection-cancelled:')) {
                return 'schedule_changed';
            }
        }
        if ($message->provider === 'fake') {
            return null;
        }
        if ($c->producer_id) {
            $consent = ProducerConsent::query()->where('producer_id', $c->producer_id)->where('purpose', $c->channel.'_messages')->latest('recorded_at')->latest('id')->first();
            if ($consent?->decision === 'declined' && ! ($message->metadata['is_opt_out_confirmation'] ?? false)) {
                return 'consent_revoked';
            }
        }
        $connection = $message->provider === 'evolution' ? app(EvolutionConnectionManager::class) : null;
        if (! ($connection ? $connection->automationEnabled() : config('messaging.live_enabled'))) {
            return 'live_disabled';
        }
        if (! ($connection ? $connection->noticeApproved() : config('messaging.notice_approved'))) {
            return 'notice_not_approved';
        }
        if ($message->provider === 'evolution') {
            if (! $connection->enabled() || ! config('messaging.evolution.api_key') || $connection->account() === '' || $c->account !== $connection->account()) {
                return 'whatsapp_not_configured';
            }
            if ($connection->isQr()) {
                if ($connection->current()?->status !== 'connected') {
                    return 'whatsapp_disconnected';
                }
                if (isset($message->metadata['template'])) {
                    return 'template_not_supported';
                }

                return null;
            }
            $template = $message->metadata['template'] ?? null;
            $templateKey = $message->metadata['template_key'] ?? '';
            if ($template !== null && ($c->consent !== 'granted' || $template !== (config('messaging.approved_templates')[$templateKey] ?? null))) {
                return 'template_not_approved';
            }
            if ($template === null && (! $c->last_inbound_at || $c->last_inbound_at->lessThanOrEqualTo(now()->subHours(24)))) {
                return 'approved_template_required';
            }
        } elseif ($message->provider === 'twilio') {
            if (! config('messaging.twilio.enabled') || ! config('messaging.twilio.auth_token') || ! config('messaging.twilio.from') || $c->account !== (string) config('messaging.twilio.account_sid') || ! str_starts_with((string) config('messaging.twilio.status_url'), 'https://')) {
                return 'sms_not_configured';
            }
            if (! config('messaging.twilio.two_way_verified')) {
                return 'sms_two_way_unverified';
            }
            if (app(SmsEncoding::class)->estimate($message->body)['segments'] > config('messaging.max_sms_segments')) {
                return 'sms_segment_limit';
            }
        }

        return null;
    }
}
