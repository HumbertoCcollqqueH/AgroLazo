<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\Role;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\EvolutionConnectionManager;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\MessageIngress;
use App\Services\Messaging\SmsEncoding;
use App\Services\ProducerIdentity;
use App\Services\ProducerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MessagingController extends Controller
{
    public function index(Request $request, ProducerIdentity $identity): View
    {
        Gate::authorize('manage-messaging');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', Rule::in(['whatsapp', 'sms'])],
            'attention' => ['nullable', Rule::in(['automatic', 'coordinator', 'pending_consent', 'declined'])],
            'state' => ['nullable', Rule::in(array_keys(Conversation::STATE_LABELS))],
            'delivery' => ['nullable', Rule::in(['issues', 'queued'])],
        ]);
        $filters['q'] = trim($filters['q'] ?? '');
        $query = Conversation::query()->with('producer')->withCount([
            'messages as delivery_issues_count' => fn ($messages) => $messages->where('direction', 'outbound')->whereIn('status', ['failed', 'uncertain', 'blocked']),
        ]);
        if ($filters['q'] !== '') {
            if (preg_match('/\A[\d\s+().-]+\z/u', $filters['q']) === 1) {
                $phone = preg_replace('/[\s().-]+/u', '', $filters['q']);
                if (preg_match('/\A519[0-9]{8}\z/', $phone) === 1) {
                    $phone = '+'.$phone;
                }
                try {
                    $phone = $identity->normalizePhone($phone);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['q' => 'Busca con el teléfono completo: 9 dígitos para Perú o +código de país y número. También puedes escribir el nombre del productor.']);
                }
                $query->where('phone_lookup', $identity->lookup('phone', $phone));
            } else {
                $name = '%'.addcslashes(Str::lower(Str::ascii($filters['q'])), '%_\\').'%';
                $query->whereHas('producer', fn ($producers) => $producers->whereRaw("translate(lower(full_name), 'áéíóúüñ', 'aeiouun') LIKE ?", [$name]));
            }
        }
        foreach (['channel', 'state'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        match ($filters['attention'] ?? '') {
            'automatic' => $query->where('human_assigned', false)->where('consent', 'granted'),
            'coordinator' => $query->where('human_assigned', true),
            'pending_consent' => $query->where('consent', 'pending'),
            'declined' => $query->where('consent', 'declined'),
            default => null,
        };
        if (! empty($filters['delivery'])) {
            $statuses = $filters['delivery'] === 'issues' ? ['failed', 'uncertain', 'blocked'] : ['queued'];
            $query->whereHas('messages', fn ($messages) => $messages->where('direction', 'outbound')->whereIn('status', $statuses));
        }

        $manager = app(EvolutionConnectionManager::class);
        $whatsapp = ['status' => $manager->current()?->status ?? 'not_configured', 'automation_enabled' => $manager->automationEnabled()];

        return view('messaging.index', [
            'conversations' => $query->latest('updated_at')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value): bool => filled($value)),
            'issues' => Message::query()->where('direction', 'outbound')->whereIn('status', ['failed', 'uncertain', 'blocked'])->count(),
            'outboxPending' => OutboxEvent::query()->whereIn('status', ['pending', 'dispatched'])->count(),
            'whatsapp' => $whatsapp,
        ]);
    }

    public function show(Conversation $conversation): View
    {
        Gate::authorize('manage-messaging');

        return view('messaging.show', ['conversation' => $conversation->load('producer'), 'messages' => $conversation->messages()->latest('id')->paginate(30), 'sms' => app(SmsEncoding::class)]);
    }

    public function simulator(): View
    {
        $this->allowSimulator();

        return view('messaging.simulator');
    }

    private function allowSimulator(): void
    {
        Gate::authorize('manage-messaging');
        abort_unless(app()->environment(['local', 'testing']) && config('messaging.simulator_enabled'), 404);
    }

    public function simulate(Request $request, MessageIngress $ingress, ConversationEngine $engine, MessageDelivery $delivery): RedirectResponse
    {
        $this->allowSimulator();
        $data = $request->validate(['phone' => 'required|string|max:30', 'channel' => ['required', Rule::in(['whatsapp', 'sms'])], 'body' => 'required|string|max:1600', 'external_id' => 'required|uuid']);
        $message = DB::transaction(function () use ($request, $ingress, $data): ?Message {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            Gate::forUser(User::query()->findOrFail($request->user()->id))->authorize('manage-messaging');

            return $ingress->receive('fake', 'local-simulator', $data['channel'], $data['external_id'], $data['phone'], $data['body'], CarbonImmutable::now());
        });
        if ($message === null) {
            return back()->with('status', 'Sin respuesta automática: el teléfono no pertenece a un productor habilitado. Regístralo primero en Productores.');
        }
        $engine->process($message->conversation_id);
        foreach ($message->conversation->messages()->where('direction', 'outbound')->where('status', 'queued')->pluck('id') as $id) {
            $delivery->deliver($id);
        }
        OutboxEvent::query()->whereIn('event_key', ['process:'.$message->id, 'deliver:'.($message->conversation->messages()->where('deduplication_key', 'reply:'.$message->id)->value('id') ?? '')])->update(['status' => 'done', 'lease_until' => null]);

        return redirect()->route('messaging.show', $message->conversation_id)->with('status', 'Simulación procesada. No se envió ningún mensaje a teléfonos reales.');
    }

    public function assist(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('manage-messaging');
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume'])], 'reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($request, $conversation, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-messaging');
            $current = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $current->update(['human_assigned' => $data['action'] === 'pause']);
            AuditLog::record($actor, 'conversation.'.$data['action'], $current, [], 'assisted_operation');
            $current->messages()->create(['direction' => 'outbound', 'provider' => $current->provider, 'account' => $current->account, 'deduplication_key' => 'note:'.Str::uuid(), 'body' => 'Nota interna: '.$data['reason'], 'status' => 'cancelled', 'metadata' => ['internal_note' => true, 'actor_id' => $actor->id]]);
        });

        return back()->with('status', 'Atención de la conversación actualizada.');
    }

    public function register(Request $request, Conversation $conversation, ProducerRegistry $registry): RedirectResponse
    {
        Gate::authorize('manage-messaging');
        Gate::authorize('manage-producers');
        $data = $request->validate(['full_name' => 'required|string|min:3|max:150', 'dni' => ['required', 'string', 'regex:/\A[0-9]{8}\z/'], 'type' => ['required', Rule::in(array_keys(Producer::TYPES))]]);
        $producer = DB::transaction(function () use ($request, $conversation, $registry, $data): Producer {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-messaging');
            $c = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($c->producer_id || $c->consent !== 'granted' || $c->state !== 'WAITING_IDENTITY_VERIFICATION') {
                throw ValidationException::withMessages(['conversation' => 'El prerregistro debe tener consentimiento y estar pendiente de identidad, sin productor vinculado.']);
            }
            $producer = $registry->create($actor, [...$data, 'phone' => $c->phone]);
            $c->update(['producer_id' => $producer->id, 'context' => [], 'human_assigned' => false]);
            ProducerConsent::query()->create(['producer_id' => $producer->id, 'conversation_id' => $c->id, 'recorded_by' => $actor->id, 'purpose' => $c->provider === 'fake' ? 'simulator_messages' : $c->channel.'_messages', 'notice_version' => $c->notice_version, 'channel' => $c->provider === 'fake' ? 'simulator' : $c->channel, 'decision' => 'granted', 'recorded_at' => $c->consented_at]);
            AuditLog::record($actor, 'conversation.producer_registered', $c, ['producer_id' => $producer->id], 'assisted_identity_capture');

            return $producer;
        });

        return redirect()->route('producers.show', $producer)->with('status', 'Identidad registrada desde la conversación. Completa la verificación del productor en esta ficha.');
    }

    public function receipt(Request $request, Conversation $conversation, MessageIngress $ingress): RedirectResponse
    {
        $this->allowSimulator();
        abort_unless($conversation->provider === 'fake', 404);
        $data = $request->validate(['message_id' => 'required|integer', 'status' => ['required', Rule::in(['sent', 'delivered', 'read', 'failed'])]]);
        $message = $conversation->messages()->where('direction', 'outbound')->whereNotNull('external_id')->findOrFail($data['message_id']);
        $ingress->receipt('fake', $conversation->account, $message->external_id, $data['status'], CarbonImmutable::now());

        return back()->with('status', 'Recibo simulado guardado.');
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('manage-messaging');
        $data = $request->validate(['source_key' => 'required|uuid', 'body' => 'nullable|required_without:template_key|string|max:1600', 'template_key' => ['nullable', Rule::in(array_keys(config('messaging.approved_templates')))]]);
        DB::transaction(function () use ($request, $conversation, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-messaging');
            $c = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($c->consent !== 'granted') {
                throw ValidationException::withMessages(['body' => 'El contacto debe autorizar este canal antes de enviar respuestas del coordinador.']);
            }
            $key = $data['template_key'] ?? null;
            $template = $key ? config('messaging.approved_templates')[$key] : null;
            if ($template && ($c->channel !== 'whatsapp' || ! is_string($template['name'] ?? null) || ! is_string($template['language'] ?? null))) {
                throw ValidationException::withMessages(['template_key' => 'La plantilla requiere WhatsApp, nombre e idioma configurados.']);
            }
            $body = $template ? 'Plantilla: '.$template['name'].' · '.($template['preview'] ?? 'Revisa el contenido aprobado en Meta.') : MessageIngress::redact($data['body']);
            $metadata = ['actor_id' => $actor->id, 'template_key' => $key, 'template' => $template];
            $deduplicationKey = 'staff:'.$data['source_key'];
            $existing = Message::query()->where('deduplication_key', $deduplicationKey)->first();
            if ($existing) {
                if ($existing->conversation_id !== $c->id || $existing->body !== $body || $existing->metadata !== $metadata) {
                    throw ValidationException::withMessages(['source_key' => 'La referencia ya se utilizó con otra respuesta. Recarga la conversación.']);
                }

                return;
            }
            $message = $c->messages()->create(['direction' => 'outbound', 'provider' => $c->provider, 'account' => $c->account, 'deduplication_key' => $deduplicationKey, 'body' => $body, 'metadata' => $metadata, 'status' => 'queued']);
            OutboxEvent::enqueue('deliver:'.$message->id, 'deliver', ['message_id' => $message->id]);
            AuditLog::record($actor, 'conversation.reply_queued', $c, ['message_id' => $message->id], 'staff_requested_reply');
        });

        return back()->with('status', 'Respuesta en cola. Su estado indicará si fue aceptada, entregada o bloqueada.');
    }
}
