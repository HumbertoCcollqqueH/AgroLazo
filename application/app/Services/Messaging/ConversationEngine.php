<?php

namespace App\Services\Messaging;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Location;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Services\OfferRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationEngine
{
    public function __construct(private OfferParser $parser, private OfferRegistry $offers, private ProducerPriceFlow $prices) {}

    public function process(int $conversationId): void
    {
        DB::transaction(function () use ($conversationId): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            $messages = $conversation->messages()->where('direction', 'inbound')->whereNull('processed_at')->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($messages as $message) {
                $reply = $this->respond($conversation, $message);
                $message->update(['processed_at' => now(), 'status' => 'processed']);
                $conversation->last_processed_at = $conversation->last_processed_at?->max($message->received_at) ?? $message->received_at;
                $conversation->save();
                if ($reply !== null) {
                    $outgoing = $conversation->messages()->firstOrCreate(['deduplication_key' => 'reply:'.$message->id], [
                        'direction' => 'outbound', 'provider' => $conversation->provider, 'account' => $conversation->account,
                        'body' => $reply, 'status' => 'queued', 'metadata' => ['reply_to' => $message->id, 'is_opt_out_confirmation' => in_array($this->parser->normalized($message->body), ['BAJA', 'STOP'], true), 'is_onboarding' => $conversation->consent === 'pending'],
                    ]);
                    if ($conversation->state === 'OFFER_REVIEW') {
                        $conversation->context = [...($conversation->context ?? []), 'review_message_id' => $outgoing->id];
                        $conversation->save();
                    }
                    if (in_array($conversation->state, ['PRICE_CLASSIFICATION', 'PRICE_REVIEW'], true)) {
                        $conversation->context = [...($conversation->context ?? []), 'price_review_message_id' => $outgoing->id];
                        $conversation->save();
                    }
                    OutboxEvent::enqueue('deliver:'.$outgoing->id, 'deliver', ['message_id' => $outgoing->id]);
                }
            }
        });
    }

    private function respond(Conversation $c, Message $message): ?string
    {
        if (config('platform.internal_communication') && $c->provider !== 'fake') {
            $message->metadata = [...($message->metadata ?? []), 'ignored_reason' => 'internal_channel_only'];

            return null;
        }
        if (! app(RegisteredContactPolicy::class)->accepts($c)) {
            $message->metadata = [...($message->metadata ?? []), 'ignored_reason' => 'unregistered_contact'];

            return null;
        }
        $connection = $c->provider === 'evolution' ? app(EvolutionConnectionManager::class)->current() : null;
        if ($connection && ! $connection->automation_enabled) {
            $message->metadata = [...($message->metadata ?? []), 'ignored_reason' => 'automation_disabled'];

            return null;
        }
        $raw = trim($message->body);
        $text = $this->parser->normalized($raw);
        $context = $c->context ?? [];
        if (in_array($text, ['BAJA', 'STOP'], true)) {
            if ($c->consent === 'declined') {
                return null;
            }
            $this->consent($c, $message, 'declined');
            $c->fill(['context' => [], 'state' => 'OPTED_OUT', 'human_assigned' => false]);
            $c->messages()->where('direction', 'outbound')->whereIn('status', ['queued', 'blocked'])->update(['status' => 'cancelled', 'error_code' => 'consent_revoked']);

            return 'Baja registrada para este canal. Tus ofertas existentes requieren revisión con el coordinador. Para volver, escribe ALTA.';
        }
        if ($c->consent === 'declined' && $text !== 'ALTA') {
            return null;
        }
        if ($c->last_processed_at && $message->received_at->lessThan($c->last_processed_at)) {
            $message->metadata = [...($message->metadata ?? []), 'ignored_reason' => 'late_event'];

            return null;
        }
        if ($text === 'ALTA' && $c->consent === 'declined') {
            $c->fill(['consent' => 'pending', 'state' => 'NEW', 'context' => []]);
            $context = [];
        }
        if ($c->state === 'NEW') {
            if (! $c->producer_id) {
                $c->producer_id = Producer::query()->where('phone_lookup', $c->phone_lookup)->value('id');
            }
            $c->state = 'WAITING_CONSENT';
            $c->notice_version = $connection?->notice_version ?? (string) config('messaging.notice_version');
            $c->context = ['notice_text' => $connection?->notice_text ?? (string) config('messaging.notice_text')];
            $this->rememberOffer($c, $message);

            return $this->onboarding($c);
        }
        if ($c->state === 'READY' && $c->consent === 'granted') {
            $text = match ($text) {
                '2' => 'ESTADO',
                '3' => 'AYUDA',
                default => $text,
            };
        }
        if ($text === 'AYUDA') {
            $c->human_assigned = true;

            return 'Un coordinador revisará esta conversación. No envíes DNI ni datos bancarios aquí. El asistente queda en pausa. Escribe MENU para volver al asistente automático.';
        }
        if ($c->human_assigned) {
            if (! in_array($text, ['MENU', 'INICIO'], true)) {
                $message->metadata = [...($message->metadata ?? []), 'ignored_reason' => 'human_assigned'];

                return null;
            }
            $c->human_assigned = false;
        }
        if ($c->state === 'WAITING_CONSENT') {
            $acceptedOffer = preg_match('/\AACEPTO(?:[.,:;!]\s*|\s+)(.+)\z/u', $text, $acceptance) === 1
                ? $this->parser->parse($acceptance[1]) : null;
            $acceptsWithOffer = $acceptedOffer && (isset($acceptedOffer['fields']['product_id']) || isset($acceptedOffer['fields']['quantity']));
            if (! in_array($text, ['ACEPTO', 'SI'], true) && ! $acceptsWithOffer) {
                $this->rememberOffer($c, $message);

                return $this->onboarding($c);
            }
            $pending = ($c->context ?? [])['pending_offer'] ?? null;
            $pendingExpiresAt = ($c->context ?? [])['pending_offer_expires_at'] ?? null;
            $this->consent($c, $message, 'granted');
            $c->context = [];
            if ($c->producer_id) {
                $reply = $this->ready($c);
                if ($c->state === 'READY') {
                    $parsed = $acceptsWithOffer ? $acceptedOffer
                        : ($pendingExpiresAt && now()->lessThan($pendingExpiresAt) ? $pending : null);
                    if ($parsed && $this->hasOfferDetails($parsed)) {
                        return 'Gracias por autorizar. Retomamos los datos que indicaste.'."\n".$this->startOffer($c, $parsed);
                    }
                }

                return $reply;
            }
            $c->state = 'WAITING_NAME';

            return '¿Cuál es tu nombre completo? No incluyas tu DNI.';
        }
        if ($c->state === 'WAITING_NAME') {
            if (mb_strlen($raw) < 3 || mb_strlen($raw) > 150 || ! preg_match('/\A[\p{L}\p{M}\s.\'-]+\z/u', $raw)) {
                return 'Escribe tu nombre completo usando letras, sin documento de identidad.';
            }
            $c->context = [...$context, 'full_name' => $raw];
            $c->state = 'WAITING_PRODUCER_TYPE';

            return 'Tipo de productor: 1 Individual, 2 Familiar, 3 Asociación, 4 Cooperativa, 5 Otro. Responde el número.';
        }
        if ($c->state === 'WAITING_PRODUCER_TYPE') {
            $types = array_keys(Producer::TYPES);
            if (! in_array($text, ['1', '2', '3', '4', '5'], true)) {
                return 'Selecciona 1 Individual, 2 Familiar, 3 Asociación, 4 Cooperativa o 5 Otro.';
            }
            $c->context = [...$context, 'type' => $types[(int) $text - 1]];
            $c->state = 'WAITING_IDENTITY_VERIFICATION';

            return 'Prerregistro recibido. El coordinador completará tu identidad por un canal seguro y verificará el registro. No envíes tu DNI aquí.';
        }
        if ($c->producer_id) {
            $producer = $c->producer()->first();
            if (! $producer || $producer->phone_lookup !== $c->phone_lookup || ! $producer->canPublishOffers()) {
                $c->state = 'WAITING_IDENTITY_VERIFICATION';
                $c->context = array_diff_key($context, array_flip(['draft', 'draft_key', 'token', 'choices', 'expires_at']));

                return 'Tu registro requiere revisión del coordinador antes de registrar ofertas. Escribe AYUDA para solicitar atención.';
            }
        } else {
            return 'El coordinador debe completar y verificar tu registro. Escribe AYUDA si necesitas asistencia.';
        }
        if ($c->state === 'WAITING_IDENTITY_VERIFICATION') {
            $c->state = 'READY';
        }
        if (in_array($c->state, ['PRICE_CLASSIFICATION', 'PRICE_REVIEW'], true)) {
            if (in_array($text, ['VENDER', 'OTRO', 'OTRO PRODUCTO', 'MENU', 'INICIO'], true)) {
                $c->fill(['state' => 'READY', 'context' => ['deferred_price' => ['state' => $c->state, 'context' => $c->context]]]);
                $context = $c->context;
            } else {
                return $this->prices->respond($c, $message, $text);
            }
        }
        if ($c->state === 'READY' && $text === 'PRECIO' && isset($context['deferred_price'])) {
            $c->fill(['state' => $context['deferred_price']['state'], 'context' => $context['deferred_price']['context']]);

            return $this->prices->respond($c, $message, $text);
        }
        if ($c->state === 'OFFER_REVIEW') {
            $text = match ($text) {
                '1', 'SI', 'CONFIRMO' => 'CONFIRMAR',
                '2' => 'CORREGIR',
                '3' => 'CANCELAR',
                default => $text,
            };
        }
        if ($text === 'ESTADO') {
            $offer = Offer::query()->where('producer_id', $c->producer_id)->latest('id')->first();

            return $offer ? 'Oferta #'.$offer->id.': '.$this->prices->offerStatus($offer) : 'Aún no tienes ofertas registradas. Escribe VENDER para empezar.';
        }
        if ($text === 'CANCELAR') {
            if ($c->state === 'READY') {
                return 'No tienes un borrador activo para descartar. Tus ofertas registradas conservan su estado. Escribe ESTADO para consultarlas, VENDER para iniciar otra o AYUDA para solicitar una cancelación a coordinación.';
            }
            $c->fill(['state' => 'READY', 'context' => array_intersect_key($context, ['deferred_price' => true])]);

            return 'Borrador descartado. Tus ofertas ya registradas conservan su estado. Escribe VENDER para empezar otra.';
        }
        if (isset($context['expires_at']) && now()->greaterThan($context['expires_at'])) {
            $c->fill(['state' => 'READY', 'context' => array_intersect_key($context, ['deferred_price' => true])]);
            $context = $c->context;

            return 'El borrador venció. Escribe VENDER para empezar de nuevo con los datos de tu ficha.'
                .(isset($context['deferred_price']) ? ' Tu propuesta de precio anterior sigue guardada; escribe PRECIO para revisarla.' : '');
        }
        if ($c->state === 'OFFER_EDIT') {
            return $this->editDraft($c, $text);
        }
        if (preg_match('/\A(?:HOLA|BUENOS DIAS|BUENAS TARDES|BUENAS NOCHES|MENU|INICIO|ACEPTO)[!.?]*\z/', $text)) {
            return $c->state === 'READY'
                ? $this->menu($c)
                : 'Tu borrador sigue guardado. Continúa con este paso o escribe CANCELAR para descartarlo.'."\n".$this->next($c);
        }
        if (str_starts_with($text, 'CONFIRMAR')) {
            if ($c->state !== 'OFFER_REVIEW') {
                if ($c->state === 'READY') {
                    $offer = Offer::query()->where('producer_id', $c->producer_id)->find($context['last_offer_id'] ?? null);

                    return $offer
                        ? 'Oferta #'.$offer->id.': '.$this->prices->offerStatus($offer)
                        : 'Primero prepara tu oferta. Escribe VENDER para empezar.';
                }

                return 'Todavía faltan datos antes de confirmar. '.$this->next($c);
            }
            $legacy = preg_replace('/\s+/u', '', $text);
            if (($text !== 'CONFIRMAR' && (! filled($context['token'] ?? null) || ! hash_equals('CONFIRMAR'.$context['token'], $legacy)))
                || ! $this->followsSummary($c, $message)) {
                return 'Revisa este resumen y responde solo CONFIRMAR o 1; no necesitas copiar ningún código.'."\n".$this->summary($c);
            }
            if (($context['product_scope'] ?? null) !== 'catalog' && $c->producer()->firstOrFail()->products()->exists() && ! $this->registeredProducts($c)->contains('id', $context['draft']['product_id'])) {
                return 'Los productos de tu ficha cambiaron. Revisa el producto antes de confirmar.'."\n".$this->next($c);
            }
            try {
                $offer = $this->offers->submitMessage($c->producer()->firstOrFail(), $context['draft'], $context['draft_key'], $c->provider === 'fake' ? 'simulator' : $c->channel);
            } catch (ValidationException $e) {
                if (isset($e->errors()['available_from']) || isset($e->errors()['available_until'])) {
                    return 'La fecha de disponibilidad necesita actualizarse. Conservé tu producto y cantidad.'."\n".$this->editDraft($c, 'FECHAS');
                }

                return 'No se registró la oferta: '.collect($e->errors())->flatten()->first().' Escribe CORREGIR para cambiar los datos o AYUDA.';
            }
            $producer = $c->producer()->firstOrFail();
            $added = $producer->products()->syncWithoutDetaching([$offer->product_id]);
            if ($added['attached'] !== []) {
                AuditLog::record(null, 'producer.crop_declared', $producer, ['product_id' => $offer->product_id, 'message_id' => $message->id, 'offer_id' => $offer->id], 'Producción declarada por el titular al confirmar su oferta.');
            }
            $c->fill(['state' => 'READY', 'context' => [...array_intersect_key($context, ['deferred_price' => true]), 'last_offer_id' => $offer->id]]);

            return 'Oferta #'.$offer->id.' registrada y pendiente de revisión. Después de verificar los datos consultaremos una referencia de mercado vigente y te enviaremos el precio por '.$offer->baseUnit->code.' y el total para que los confirmes. Con tu aceptación se publicará para los compradores. Escribe ESTADO para consultar.';
        }
        if ($text === 'CORREGIR') {
            if (! isset($context['draft'])) {
                return 'No tienes un borrador pendiente. Escribe VENDER para empezar.';
            }
            $c->state = 'OFFER_EDIT';

            return $this->editMenu();
        }
        if (in_array($text, ['OTRO', 'OTRO PRODUCTO', 'OTRO CULTIVO'], true)) {
            if (! isset($context['draft'])) {
                $c->context = [...array_intersect_key($context, ['deferred_price' => true]), 'draft' => [], 'draft_key' => (string) Str::uuid(), 'expires_at' => now()->addDays((int) config('messaging.draft_ttl_days', 7))->toIso8601String()];
            } else {
                $this->editDraft($c, 'PRODUCTO');
            }
            $c->context = [...$c->context, 'product_scope' => 'catalog', 'product_selection' => true];

            return $this->next($c);
        }
        if (isset($context['draft']) && in_array($text, ['CANTIDAD', 'FECHAS', 'UBICACION', 'PRODUCTO', 'VARIEDAD', 'CALIDAD'], true)) {
            return $this->editDraft($c, $text);
        }
        if ($c->state === 'READY') {
            $parsed = $this->parser->parse($raw);
            $request = $text === '1'
                || preg_match('/\A(VENDER|PUBLICAR|OFERTAR)(?:\s|\z)/', $text)
                || preg_match('/\b(?:AGREGAR|ANADIR|REGISTRAR|CARGAR|INGRESAR)\b.*\b(?:PRODUCTO|PRODUCTOS|OFERTA|OFERTAS|KILOS|KG|COSECHA)\b/', $text);
            if (! $request && ! isset($parsed['fields']['product_id']) && ! isset($parsed['fields']['quantity']) && $parsed['errors'] === []) {
                return 'Elige una opción para continuar.'."\n".$this->menu($c);
            }

            return $this->startOffer($c, $parsed);
        }
        if (preg_match('/\A(VENDER|PUBLICAR|OFERTAR)(?:\s|\z)/', $text)) {
            return 'Tu oferta sigue guardada. Puedes completarla, escribir CORREGIR para cambiar un dato o CANCELAR para descartarla.'."\n".$this->next($c);
        }
        if ($c->state === 'OFFER_REVIEW') {
            return $this->summary($c).' También puedes escribir CORREGIR o CANCELAR.';
        }

        return $this->answerField($c, $message);
    }

    private function ready(Conversation $c): string
    {
        $c->state = $c->producer()->first()?->canPublishOffers() ? 'READY' : 'WAITING_IDENTITY_VERIFICATION';

        return $c->state === 'READY' ? $this->menu($c) : 'Tu registro está pendiente de revisión del coordinador. No envíes documentos por este canal.';
    }

    private function menu(Conversation $c): string
    {
        $producer = $c->producer()->firstOrFail();

        return 'Hola, '.$producer->full_name.'. Tu registro está verificado.'."\n".$this->profile($c)."\n"
            ."1 Agregar producto o nueva oferta\n2 Consultar estado de mi última oferta\n3 Hablar con un coordinador\n"
            .'Responde 1, 2 o 3. Si también produces algo que aún no figura en tu ficha, escribe OTRO para elegirlo. También puedes indicar el producto y cantidad. MENU muestra estas opciones; AYUDA solicita atención y BAJA desactiva las respuestas.'
            .(isset($c->context['deferred_price']) ? "\nTienes una propuesta anterior pendiente: escribe PRECIO para retomarla." : '');
    }

    private function profile(Conversation $c): string
    {
        $producer = $c->producer()->firstOrFail();
        if ($producer->products()->exists()) {
            $names = $this->registeredProducts($c)->pluck('name');

            return 'Tu ficha: '.(Producer::TYPES[$producer->type] ?? 'Productor registrado').'. '
                .($names->isNotEmpty() ? 'Productos registrados: '.$names->implode(', ').'.' : 'Tus productos necesitan actualización del coordinador.');
        }
        $products = $this->previousProducts($c)->take(5)->pluck('name');
        $history = $products->isNotEmpty()
            ? 'Productos en tus ofertas anteriores: '.$products->implode(', ').'. Puedes elegir uno de ellos u otro producto del catálogo.'
            : 'Todavía no tenemos productos en tu historial. Podrás indicar qué produces o elegir un producto del catálogo.';

        return 'Tu ficha: '.(Producer::TYPES[$producer->type] ?? 'Productor registrado').'. '.$history;
    }

    private function onboarding(Conversation $c): string
    {
        $notice = ($c->context ?? [])['notice_text'] ?? (string) config('messaging.notice_text');
        if ($c->channel === 'sms') {
            return $notice."\nPara continuar responde ACEPTO. Para atención asistida, AYUDA; para salir, BAJA.";
        }
        $producer = $c->producer()->first();
        $greeting = $producer
            ? 'Hola, '.$producer->full_name.'. Soy el asistente de AgroLazo. Ya encontré tu ficha; no necesitas registrarte otra vez.'."\n".$this->profile($c)
            : 'Hola, soy el asistente de AgroLazo.';
        $options = $producer?->canPublishOffers()
            ? 'Después de autorizar podrás: 1 Agregar producto o nueva oferta; 2 Consultar estado; 3 Hablar con un coordinador.'
            : 'Tu registro requiere verificación del coordinador antes de registrar ofertas. Puedes solicitar atención escribiendo AYUDA.';
        $pending = isset($c->context['pending_offer'])
            ? "\nYa recibí los datos de tu producto; los retomaremos después de tu autorización."
            : '';

        return $greeting."\n\n".$notice."\n".$options.$pending
            ."\nPara continuar responde ACEPTO. También puedes escribir ACEPTO seguido de tu producto y cantidad. Para atención asistida, AYUDA; para salir, BAJA.";
    }

    private function rememberOffer(Conversation $c, Message $message): void
    {
        $parsed = $this->parser->parse($message->body);
        if ($this->hasOfferDetails($parsed)) {
            $c->context = [...($c->context ?? []), 'pending_offer' => $parsed,
                'pending_offer_expires_at' => $message->received_at->addDays((int) config('messaging.draft_ttl_days', 7))->toIso8601String()];
        }
    }

    /** @param array{fields: array, errors: array} $parsed */
    private function hasOfferDetails(array $parsed): bool
    {
        return isset($parsed['fields']['product_id']) || isset($parsed['fields']['quantity']) || $parsed['errors'] !== [];
    }

    /** @param array{fields: array, errors: array} $parsed */
    private function startOffer(Conversation $c, array $parsed): string
    {
        $c->context = [...array_intersect_key($c->context ?? [], ['deferred_price' => true]), 'draft' => $parsed['fields'], 'draft_key' => (string) Str::uuid(),
            'expires_at' => now()->addDays((int) config('messaging.draft_ttl_days', 7))->toIso8601String()];

        $next = $this->next($c);
        $draft = $c->context['draft'] ?? [];
        $details = isset($draft['product_id']) ? 'Producto: '.Product::query()->find($draft['product_id'])?->name.'. Para elegir otro de tus productos escribe PRODUCTO; para un cultivo nuevo, OTRO. ' : '';
        if (($c->context['location_from_profile'] ?? false) && isset($draft['location_id'])) {
            $details .= 'Usaremos tu ubicación registrada: '.Location::query()->find($draft['location_id'])?->name.'. Puedes cambiarla escribiendo UBICACION.';
        }

        return ($details !== '' ? $details."\n" : '')
            .($parsed['errors'] ? implode(' ', $parsed['errors'])."\n" : '').$next;
    }

    private function registeredProducts(Conversation $c): Collection
    {
        return $c->producer()->firstOrFail()->products()->with(['category', 'baseUnit'])->orderBy('name')->get()
            ->filter(fn (Product $product): bool => $product->isUsable());
    }

    private function followsSummary(Conversation $c, Message $message): bool
    {
        $summary = isset($c->context['review_message_id'])
            ? $c->messages()->where('direction', 'outbound')->find($c->context['review_message_id'])
            : $c->messages()->where('direction', 'outbound')->latest('id')->limit(100)->get()
                ->first(fn (Message $reply): bool => str_contains($reply->body, 'Resumen:'));

        return $summary !== null && $message->id > $summary->id
            && $message->received_at->timestamp >= $summary->created_at->timestamp;
    }

    private function editMenu(): string
    {
        return "¿Qué dato quieres cambiar?\n1 Cantidad\n2 Fechas\n3 Ubicación\n4 Producto\n5 Variedad\n6 Calidad\n0 Volver al resumen\nSolo cambiaremos el dato que elijas.";
    }

    private function editDraft(Conversation $c, string $text): string
    {
        $field = match ($text) {
            '1', 'CANTIDAD' => 'quantity', '2', 'FECHAS' => 'dates', '3', 'UBICACION' => 'location_id',
            '4', 'PRODUCTO' => 'product_id', '5', 'VARIEDAD' => 'variety_id', '6', 'CALIDAD' => 'quality_grade_id',
            default => null,
        };
        if ($text === '0') {
            return $this->next($c);
        }
        if ($field === null) {
            return $this->editMenu();
        }
        $context = $c->context ?? [];
        $draft = $context['draft'] ?? [];
        $keys = match ($field) {
            'quantity' => ['quantity', 'unit_id'],
            'dates' => ['available_from', 'available_until'],
            'product_id' => ['product_id', 'variety_id', 'quality_grade_id', 'quantity', 'unit_id'],
            default => [$field],
        };
        $draft = array_diff_key($draft, array_flip($keys));
        $context = array_diff_key($context, array_flip(['token', 'review_message_id', 'choices']));
        $c->context = [...$context, 'draft' => $draft, 'draft_key' => (string) Str::uuid(), 'editing_field' => $field,
            'expires_at' => now()->addDays((int) config('messaging.draft_ttl_days', 7))->toIso8601String(),
            'location_manual' => $field === 'location_id' || ($context['location_manual'] ?? false),
            'location_from_profile' => $field !== 'location_id' && ($context['location_from_profile'] ?? false),
            'product_selection' => $field === 'product_id'];

        return $this->next($c);
    }

    private function previousProducts(Conversation $c): Collection
    {
        return Product::query()->with(['category', 'baseUnit'])->where('active', true)
            ->whereIn('id', Offer::query()->select('product_id')->where('producer_id', $c->producer_id))
            ->orderBy('name')->get()->filter(fn (Product $product): bool => $product->isUsable());
    }

    private function consent(Conversation $c, Message $message, string $decision): void
    {
        $c->consent = $decision;
        $c->consented_at = now();
        $version = $c->notice_version ?: (string) config('messaging.notice_version');
        ProducerConsent::query()->create(['producer_id' => $c->producer_id, 'conversation_id' => $c->id, 'recorded_by' => null,
            'purpose' => $c->provider === 'fake' ? 'simulator_messages' : $c->channel.'_messages', 'notice_version' => $version,
            'channel' => $c->provider === 'fake' ? 'simulator' : $c->channel, 'decision' => $decision, 'recorded_at' => now()]);
        $message->metadata = [...($message->metadata ?? []), 'consent_decision' => $decision, 'notice_version' => $version, 'notice_text' => ($c->context ?? [])['notice_text'] ?? null];
    }

    private function answerField(Conversation $c, Message $message): string
    {
        $context = $c->context ?? [];
        $draft = $context['draft'] ?? [];
        $text = $this->parser->normalized($message->body);
        $field = ['OFFER_PRODUCT' => 'product_id', 'OFFER_VARIETY' => 'variety_id', 'OFFER_QUALITY' => 'quality_grade_id', 'OFFER_LOCATION' => 'location_id'][$c->state] ?? null;
        if ($field) {
            if ($field === 'product_id' && $text === '0' && in_array($context['product_scope'] ?? null, ['history', 'profile'], true)) {
                $c->context = [...$context, 'product_scope' => 'catalog'];

                return $this->next($c);
            }
            $choices = $context['choices'] ?? [];
            $choice = ctype_digit($text) ? ($choices[(int) $text] ?? null) : null;
            if ($text === '0' && in_array($field, ['variety_id', 'quality_grade_id'], true)) {
                $draft[$field] = null;
            } elseif ($choice !== null) {
                $draft[$field] = $choice;
            } else {
                $parsed = $this->parser->parse($message->body);
                if (in_array($field, ['product_id', 'location_id'], true) && isset($parsed['fields'][$field])) {
                    $draft = $field === 'product_id' ? [...$draft, ...$parsed['fields']] : [...$draft, $field => $parsed['fields'][$field]];
                } else {
                    return 'No identifiqué esa opción. '.$this->next($c);
                }
            }
        } elseif ($c->state === 'OFFER_QUANTITY') {
            $quantity = trim($message->body);
            if (preg_match('/\A\d+(?:[.,]\d+)?\z/', $quantity)) {
                $quantity .= ' '.Product::query()->findOrFail($draft['product_id'])->baseUnit->code;
            }
            $result = $this->parser->quantity($quantity);
            if (! isset($result['quantity'], $result['unit_id'])) {
                return ($result['error'] ?? 'Indica cantidad y unidad, por ejemplo 80 kg.').' También puedes escribir AYUDA.';
            }
            $draft = [...$draft, ...$result];
        } elseif (in_array($c->state, ['OFFER_FROM', 'OFFER_UNTIL'], true)) {
            if ($c->state === 'OFFER_FROM' && $text === '3') {
                return 'Escribe la fecha de inicio, por ejemplo '.now('America/Lima')->addDay()->format('d/m/Y').', MAÑANA o EN 3 DIAS.';
            }
            if ($c->state === 'OFFER_FROM') {
                $input = match ($text) {
                    '1' => 'HOY', '2' => 'MAÑANA', default => $message->body
                };
                $date = $this->parser->date($input, $message->received_at);
            } else {
                $input = match ($text) {
                    '1' => 'MISMO DIA', '2' => '7 DIAS', '3' => '15 DIAS', '4' => '30 DIAS', default => $message->body
                };
                $date = $this->parser->availabilityEnd($input, $draft['available_from'], $message->received_at);
            }
            if ($date === null) {
                return 'No pude reconocer esa fecha o ya pasó. La fecha final debe ser igual o posterior al inicio.'."\n".$this->next($c);
            }
            $draft[$c->state === 'OFFER_FROM' ? 'available_from' : 'available_until'] = $date;
        }
        $c->context = [...$context, 'draft' => $draft];

        return $this->next($c);
    }

    private function next(Conversation $c): string
    {
        $context = $c->context ?? [];
        $draft = $context['draft'] ?? [];
        $producer = $c->producer()->firstOrFail();
        $hasProfileProducts = $producer->products()->exists();
        $registered = $hasProfileProducts ? $this->registeredProducts($c) : collect();
        if (! isset($draft['location_id']) && ! ($context['location_manual'] ?? false)) {
            $location = $producer->currentLocation()->with('location')->where('valid_from', '<=', now())->first()?->location;
            if ($location?->isUsable() && $location->type !== 'country') {
                $draft['location_id'] = $location->id;
                $context['location_from_profile'] = true;
            }
        }
        $c->context = [...$context, 'draft' => $draft];
        if (isset($draft['product_id']) && ($context['product_scope'] ?? null) !== 'catalog' && $hasProfileProducts && ! $registered->contains('id', $draft['product_id'])) {
            $draft = array_diff_key($draft, array_flip(['product_id', 'variety_id', 'quality_grade_id', 'quantity', 'unit_id']));
            $c->context = [...$context, 'draft' => $draft, 'product_selection' => true, 'draft_key' => (string) Str::uuid(), 'token' => null, 'review_message_id' => null];
            $c->state = 'OFFER_PRODUCT';

            return $registered->isEmpty()
                ? 'Los productos de tu ficha no están disponibles. Escribe AYUDA para actualizar tu registro con el coordinador.'
                : $this->choices($c, $registered, 'Ese producto no está habilitado en tu ficha. Elige uno de tus productos; para agregar otro escribe AYUDA:');
        }
        if (! isset($draft['product_id'])) {
            if ($hasProfileProducts && ($context['product_scope'] ?? null) !== 'catalog') {
                $rows = $registered;
                $scope = 'profile';
            } else {
                $rows = ($context['product_scope'] ?? null) !== 'catalog' ? $this->previousProducts($c) : collect();
                $scope = $rows->isNotEmpty() ? 'history' : 'catalog';
                if ($scope === 'catalog') {
                    $rows = Product::query()->with(['category', 'baseUnit'])->where('active', true)->orderBy('name')->get()->filter(fn (Product $p): bool => $p->isUsable());
                }
            }
            $context = [...$context, 'product_scope' => $scope];
            $c->context = [...$context, 'draft' => $draft];
            if ($scope === 'profile' && $rows->isEmpty()) {
                $c->state = 'OFFER_PRODUCT';

                return 'Los productos de tu ficha no están disponibles. Escribe AYUDA para actualizar tu registro con el coordinador.';
            }
            if ($scope === 'profile' && $rows->count() === 1 && ! ($context['product_selection'] ?? false)) {
                $draft['product_id'] = $rows->first()->id;
                $c->context = [...$context, 'draft' => $draft];
            } else {
                $c->state = 'OFFER_PRODUCT';
                $prompt = match ($scope) {
                    'profile' => '¿Cuál de tus productos quieres ofrecer?',
                    'history' => '¿Qué producto ofreces? Estos aparecen en tus ofertas anteriores:',
                    default => '¿Qué producto ofreces? Si es un cultivo nuevo, lo añadiremos a tu ficha cuando confirmes la oferta:',
                };

                return $this->choices($c, $rows, $prompt, otherProducts: in_array($scope, ['history', 'profile'], true));
            }
        }
        $product = Product::query()->find($draft['product_id']);
        if (! $product?->isUsable()) {
            $c->fill(['state' => 'READY', 'context' => []]);

            return 'Ese producto ya no está disponible. Escribe VENDER para empezar de nuevo.';
        }
        foreach (['variety_id' => ['OFFER_VARIETY', $product->varieties(), '¿Qué variedad?'], 'quality_grade_id' => ['OFFER_QUALITY', $product->qualityGrades(), '¿Qué calidad declaras?']] as $key => [$state, $relation, $prompt]) {
            if (! array_key_exists($key, $draft)) {
                $rows = $relation->where('active', true)->get();
                if (($context['editing_field'] ?? null) !== $key && ($rows->isEmpty() || ($rows->count() === 1 && $rows->first()->code === 'unspecified'))) {
                    $draft[$key] = $rows->first()?->id;
                    $c->context = [...$context, 'draft' => $draft];

                    continue;
                }
                $c->state = $state;

                return $this->choices($c, $rows, $prompt, true);
            }
        }
        if (! isset($draft['quantity'], $draft['unit_id'])) {
            $c->state = 'OFFER_QUANTITY';

            return '¿Qué cantidad ofreces en '.$product->baseUnit->code.' de '.$product->name.'? Responde, por ejemplo, 20 o 20 '.$product->baseUnit->code.'.';
        }
        if (! isset($draft['location_id'])) {
            $c->state = 'OFFER_LOCATION';

            return $this->choices($c, Location::query()->with('parent')->where('active', true)->where('type', '!=', 'country')->get()->filter(fn (Location $l): bool => $l->isUsable()),
                ($context['location_manual'] ?? false) ? '¿Dónde está este producto? Tu ubicación registrada no cambiará.' : 'Tu ficha no tiene una localidad disponible. ¿Dónde está el producto?');
        }
        if (! isset($draft['available_from'])) {
            $c->state = 'OFFER_FROM';

            return "¿Desde cuándo está disponible?\n1 Hoy\n2 Mañana\n3 Otra fecha\nResponde el número o escribe una fecha, por ejemplo ".now('America/Lima')->addDay()->format('d/m/Y').'.';
        }
        if (! isset($draft['available_until'])) {
            $c->state = 'OFFER_UNTIL';

            return '¿Por cuánto tiempo estará disponible desde el '.$this->displayDate($draft['available_from'])."?\n1 Solo ese día\n2 Una semana (7 días)\n3 Quince días\n4 Treinta días\nResponde el número o escribe una fecha de término, por ejemplo 23/10, o una duración como 10 días.";
        }
        $c->state = 'OFFER_REVIEW';
        $c->context = [...$context, 'draft' => $draft, 'token' => strtr(strtoupper(substr(str_replace('-', '', $context['draft_key']), 0, 12)), '0123456789', 'GHIJKLMNOP'), 'choices' => [], 'editing_field' => null];

        return $this->summary($c);
    }

    private function choices(Conversation $c, Collection $rows, string $prompt, bool $optional = false, bool $otherProducts = false): string
    {
        $choices = [];
        $lines = [];
        foreach ($rows->take(15)->values() as $index => $row) {
            $choices[$index + 1] = $row->id;
            $label = $row->name;
            if ($row instanceof Location && $row->parent) {
                $label .= ' ('.$row->parent->name.')';
            }
            $lines[] = ($index + 1).' '.$label;
        }
        $c->context = [...($c->context ?? []), 'choices' => $choices];
        if ($optional) {
            $lines[] = '0 No sé; revisión asistida';
        }
        if ($otherProducts) {
            $lines[] = '0 Otro producto del catálogo';
        }
        if ($lines === []) {
            return 'El catálogo aún no tiene opciones disponibles. Escribe AYUDA para que el coordinador complete los datos.';
        }

        return $prompt."\n".implode("\n", $lines)."\nResponde el número".($optional ? '.' : ' o el nombre exacto. Si no aparece, escribe AYUDA.');
    }

    private function summary(Conversation $c): string
    {
        $d = $c->context['draft'];
        $product = Product::query()->findOrFail($d['product_id']);
        $variety = isset($d['variety_id']) ? $product->varieties()->whereKey($d['variety_id'])->value('name') : null;
        $quality = isset($d['quality_grade_id']) ? $product->qualityGrades()->whereKey($d['quality_grade_id'])->value('name') : null;

        return 'Resumen: '.$product->name.', '.$d['quantity'].' '.Unit::query()->findOrFail($d['unit_id'])->code.', '.Location::query()->findOrFail($d['location_id'])->name.'.'
            ."\nDisponible del ".$this->displayDate($d['available_from']).' al '.$this->displayDate($d['available_until']).'.'
            ."\nVariedad: ".($variety ?? 'por revisar').'; calidad: '.($quality ?? 'por revisar').'. Después de verificar los datos te propondremos el precio de mercado y el total para confirmar.'
            ."\n\n1 Confirmar oferta\n2 Cambiar un dato\n3 Cancelar\nResponde 1 o CONFIRMAR para registrarla. No necesitas escribir ningún código. Se revisará antes de publicarse.";
    }

    private function displayDate(string $date): string
    {
        return CarbonImmutable::parse($date, 'America/Lima')->format('d/m/Y');
    }
}
