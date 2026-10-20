<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\OutboxEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\Money;
use App\Services\OfferPublication;
use App\Services\OfferRegistry;
use App\Services\ProducerPortal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProducerPriceFlow
{
    public function __construct(private AutomatedMarketPriceProvider $prices, private OfferRegistry $offers, private OfferPublication $publication) {}

    public function queue(Offer $offer): ?Message
    {
        if (config('platform.internal_communication')) {
            if ($this->eligibleOffer($offer) && ($actor = $this->verifier($offer))) {
                app(ProducerPortal::class)->propose($offer, $actor);
            }

            return null;
        }
        if (! config('market-pricing.enabled', true)) {
            return null;
        }

        return DB::transaction(function () use ($offer): ?Message {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if (! $this->eligibleOffer($offer) || ! ($actor = $this->verifier($offer))) {
                return null;
            }
            $conversations = Conversation::query()->where('producer_id', $offer->producer_id)->where('consent', 'granted')
                ->where('human_assigned', false)->orderByDesc('last_inbound_at')->lockForUpdate()->get();
            foreach ($conversations as $conversation) {
                if (! $this->eligibleConversation($conversation) || isset($conversation->context['deferred_price']) || $conversation->messages()->where('direction', 'inbound')->whereNull('processed_at')->exists()) {
                    continue;
                }
                if (in_array($conversation->state, ['PRICE_CLASSIFICATION', 'PRICE_REVIEW'], true)) {
                    if (($conversation->context['price_offer_id'] ?? null) === $offer->id) {
                        return $conversation->messages()->find($conversation->context['price_review_message_id'] ?? null);
                    }

                    continue;
                }
                if ($conversation->state !== 'READY') {
                    continue;
                }
                if ($offer->agreements()->where('offer_version', $offer->version)->exists()) {
                    return null;
                }
                if ($choices = $this->prices->classificationChoices($offer)) {
                    $conversation->fill(['state' => 'PRICE_CLASSIFICATION', 'context' => [
                        'price_offer_id' => $offer->id, 'price_offer_version' => $offer->version,
                        'price_actor_id' => $actor->id, 'price_choices' => $choices,
                    ]]);

                    return $this->outgoing($conversation, 'price-classification:'.$offer->id.':'.$offer->version, $this->classificationText($conversation));
                }

                return $this->proposal($offer, $conversation, $actor);
            }

            return null;
        });
    }

    public function respond(Conversation $conversation, Message $message, string $text): ?string
    {
        $context = $conversation->context ?? [];
        $offer = Offer::query()->lockForUpdate()->find($context['price_offer_id'] ?? null);
        $actor = isset($context['price_actor_id']) ? User::query()->find($context['price_actor_id']) : null;
        if (! config('market-pricing.enabled', true) || ! $offer || $offer->producer_id !== $conversation->producer_id
            || ! $this->eligibleConversation($conversation) || ! $actor?->hasPermission('manage-offers') || ! $actor->hasPermission('manage-lots')) {
            $conversation->human_assigned = true;

            return 'No se aceptó ningún precio. La propuesta necesita revisión del coordinador y el asistente queda en pausa. Escribe MENU para volver al asistente automático.';
        }
        if ($offer->version !== ($context['price_offer_version'] ?? null) || $offer->status !== 'verified') {
            $conversation->fill(['state' => 'READY', 'context' => ['last_offer_id' => $offer->id]]);

            return 'La propuesta anterior ya no corresponde al estado actual; este mensaje no confirmó ningún precio.'."\nOferta #".$offer->id.': '.$this->offerStatus($offer);
        }
        if ($offer->available_until->toDateString() < now('America/Lima')->toDateString()) {
            $conversation->fill(['state' => 'READY', 'context' => ['last_offer_id' => $offer->id]]);

            return 'La disponibilidad de tu oferta #'.$offer->id.' ya venció. No se aceptó ningún precio. Escribe VENDER para registrar una nueva oferta con fechas actuales.';
        }
        if ($conversation->state === 'PRICE_CLASSIFICATION') {
            return $this->classify($conversation, $message, $text, $offer, $actor);
        }
        $agreement = $offer->agreements()->find($context['price_agreement_id'] ?? null);
        if ($agreement?->status === 'proposed' && ! $agreement->valid_until->isFuture()
            && $agreement->id === $offer->agreements()->latest('version')->value('id')) {
            if ($this->proposal($offer, $conversation, $actor)) {
                return null;
            }

            return 'El precio anterior venció. Aún no hay una referencia vigente para renovarlo. No se aceptó ningún precio. Escribe VENDER para otra oferta o AYUDA para revisión.';
        }
        if (! $agreement || $agreement->id !== $offer->agreements()->latest('version')->value('id')
            || $agreement->offer_version !== $offer->version || $agreement->status !== 'proposed' || ! $agreement->valid_until->isFuture()) {
            $conversation->fill(['state' => 'READY', 'context' => ['last_offer_id' => $offer->id]]);

            return 'Esa propuesta cambió o venció. No se confirmó ningún precio. El coordinador revisará una propuesta vigente; escribe AYUDA si necesitas atención.';
        }
        $decision = match ($text) {
            '1', 'ACEPTO PRECIO', 'CONFIRMAR PRECIO', 'CONFIRMAR', 'CONFIRMO', 'SI' => 'accepted',
            '2', 'NO ACEPTO', 'RECHAZAR', 'CANCELAR' => 'rejected',
            default => null,
        };
        if ($decision === null || ! $this->followsSummary($conversation, $message)) {
            return $this->proposalText($offer, $agreement, $context['price_reference']);
        }
        $evidence = $this->evidence($message, $decision);
        try {
            $lot = DB::transaction(function () use ($actor, $offer, $agreement, $decision, $evidence, $context): ?Lot {
                $this->offers->decideAgreement($actor, $offer, $agreement, ['version' => $offer->version, 'decision' => $decision, 'evidence' => $evidence]);

                return $decision === 'accepted' ? $this->publication->publish($actor, $offer, [
                    'version' => $offer->version, 'agreement_id' => $agreement->id,
                    'source_key' => $context['price_publication_key'], 'reason' => 'Publicación automática tras aceptación del productor por mensaje. '.$evidence,
                ]) : null;
            });
        } catch (ValidationException $exception) {
            $conversation->human_assigned = true;

            return 'No pudimos completar la confirmación y publicación: '.collect($exception->errors())->flatten()->first()
                .' No se registró esta aceptación. La conversación queda pendiente de coordinación; escribe MENU para volver al asistente.';
        }
        $conversation->fill(['state' => 'READY', 'context' => ['last_offer_id' => $offer->id, 'last_price_agreement_id' => $agreement->id]]);
        if ($decision === 'rejected') {
            $conversation->human_assigned = true;

            return 'Precio rechazado. Tu oferta #'.$offer->id.' queda guardada y sin publicar. Un coordinador revisará contigo otro precio. Escribe MENU para volver al asistente.';
        }

        return '¡Precio confirmado! Tu oferta #'.$offer->id.' ya está publicada para los compradores como '.$lot->code.'.'
            ."\n".$this->quantity($offer).' '.$offer->baseUnit->code.' × S/ '.$this->decimal($agreement->net_unit_price).' = S/ '.Money::format(Money::line($offer->normalized_quantity, $agreement->net_unit_price)).'.'
            ."\nSin descuentos al productor en esta propuesta; transporte a cargo de la plataforma, sin cobro al productor. La publicación no garantiza una venta. Escribe ESTADO para consultar o VENDER para otra oferta.";
    }

    public function offerStatus(Offer $offer): string
    {
        $options = "\nVENDER: empezar una nueva oferta con los datos de tu ficha.\nMENU: ver tus opciones.\nAYUDA: consultar con coordinación.";
        if (in_array($offer->status, ['cancelled', 'rejected', 'expired'], true)) {
            return Offer::STATUSES[$offer->status].'. Esta oferta no está disponible para comprar. Para volver a ofrecer el producto, registra una nueva oferta y confirma sus datos actuales.'.$options;
        }
        if ($offer->available_until->toDateString() < now('America/Lima')->toDateString()) {
            return 'La disponibilidad terminó el '.$offer->available_until->format('d/m/Y').'. Esta oferta ya no está disponible para comprar.'.$options;
        }
        if ($offer->status === 'verified') {
            $agreement = $offer->agreements()->where('offer_version', $offer->version)->latest('version')->first();

            return match (true) {
                $agreement?->status === 'proposed' && $agreement->valid_until->isFuture() => 'Datos verificados; falta aceptar el precio. Revisa la propuesta recibida. Si la dejaste pendiente desde el menú, escribe PRECIO para retomarla. AYUDA solicita revisión.',
                $agreement?->status === 'accepted' && $agreement->valid_until->isFuture() => 'Precio aceptado, pero la oferta todavía no está publicada. Escribe AYUDA para revisar la publicación con coordinación.',
                $agreement?->status === 'rejected' => 'Rechazaste el precio propuesto; tu oferta sigue guardada sin publicar. Escribe AYUDA para revisar otro precio o VENDER para una nueva oferta.',
                $agreement !== null => 'La propuesta de precio anterior ya no está vigente. Hace falta una nueva antes de publicar. Escribe AYUDA para revisarla o VENDER para otra oferta.',
                default => 'Datos verificados; todavía no hay una propuesta de precio. Falta completar la clasificación o contar con una referencia vigente y compatible. Escribe AYUDA para revisión o VENDER para otra oferta.',
            };
        }

        return Offer::STATUSES[$offer->status].'. '.match ($offer->status) {
            'published' => 'Publicación registrada. La disponibilidad para comprar depende del saldo y de la vigencia del precio. Escribe VENDER para otra oferta o AYUDA para consultar una compra o el saldo.',
            'needs_information' => 'Coordinación necesita completar tus datos. Escribe AYUDA para continuar la revisión.',
            'draft' => 'Todavía no está enviada a revisión. Escribe AYUDA para completar este registro o VENDER para preparar una nueva oferta.',
            default => 'Coordinación debe revisar los datos. Aún no está publicada ni tiene una venta confirmada. Tras la verificación se podrá proponer un precio si existe una referencia vigente. Escribe AYUDA para consultar o VENDER para otra oferta.',
        };
    }

    private function classify(Conversation $conversation, Message $message, string $text, Offer $offer, User $actor): ?string
    {
        $choices = $conversation->context['price_choices'] ?? [];
        $choice = ctype_digit($text) ? ($choices[(int) $text - 1] ?? null) : collect($choices)->first(fn (array $choice): bool => app(OfferParser::class)->normalized($choice['label']) === $text);
        if (! $choice || ! $this->followsSummary($conversation, $message)) {
            return (in_array($text, ['HOLA', 'ESTADO', 'PRECIO'], true) ? 'Tu oferta sigue guardada; falta elegir la variedad. ' : 'No pude identificar una variedad en tu respuesta. Elige una de estas opciones. ')."\n".$this->classificationText($conversation);
        }
        try {
            $offer = $this->offers->completeMarketVariety($actor, $offer, $choice['variety_id'], $offer->version, $this->evidence($message, 'variedad '.$choice['label']));
        } catch (ValidationException $exception) {
            $conversation->human_assigned = true;

            return 'No pudimos completar la variedad: '.collect($exception->errors())->flatten()->first().' La conversación queda pendiente de coordinación; escribe MENU para volver al asistente.';
        }
        $conversation->fill(['state' => 'READY', 'context' => ['last_offer_id' => $offer->id]]);
        if ($this->proposal($offer, $conversation, $actor)) {
            return null;
        }

        return 'Variedad registrada: '.$choice['label'].'. Tu oferta #'.$offer->id.' sigue verificada. La fuente aún no tiene un precio suficientemente reciente para esta variedad. Cuando exista una referencia vigente te enviaremos la propuesta para confirmar. Puedes escribir AYUDA para revisión asistida.';
    }

    private function proposal(Offer $offer, Conversation $conversation, User $actor): ?Message
    {
        $reference = $this->prices->forOffer($offer);
        if (! $reference || ($reference['currency'] ?? null) !== 'PEN' || ! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/', (string) $reference['unit_price']) || bccomp($reference['unit_price'], '0', 6) <= 0) {
            return null;
        }
        $observed = CarbonImmutable::parse($reference['observed_on'], 'America/Lima')->startOfDay();
        $today = CarbonImmutable::now('America/Lima')->startOfDay();
        $maximumAge = max(1, (int) config('market-pricing.max_age_days', 7));
        if ($observed->greaterThan($today) || $observed->lessThan($today->subDays($maximumAge))) {
            return null;
        }
        $until = min($offer->available_until->toDateString(), $observed->addDays($maximumAge)->toDateString());
        if ($until < $offer->available_from->toDateString()) {
            return null;
        }
        $terms = 'Propuesta automática basada en '.$reference['product_label'].', '.$reference['market'].'. Observación: '.$reference['observed_on'].'. Fuente: '.$reference['source'].'.'
            .' Referencia mayorista, no una medición del precio en chacra de la localidad del productor. Precio PEN '.$reference['unit_price'].' por '.$offer->baseUnit->code.'.'
            .' Cantidad normalizada '.$offer->normalized_quantity.' '.$offer->baseUnit->code.'. Total PEN '.Money::format(Money::line($offer->normalized_quantity, $reference['unit_price'])).'.'
            .' Sin descuentos al productor; transporte a cargo de la plataforma, sin cobro al productor. Requiere aceptación expresa antes de publicar. Proveedor: '.$reference['provider'].'.';
        $agreement = $this->offers->proposeAgreement($actor, $offer, [
            'version' => $offer->version, 'gross_unit_price' => $reference['unit_price'], 'producer_unit_cost' => '0.000000',
            'terms' => $terms, 'valid_until' => $until,
        ]);
        $conversation->fill(['state' => 'PRICE_REVIEW', 'context' => [
            'price_offer_id' => $offer->id, 'price_offer_version' => $offer->version, 'price_actor_id' => $actor->id,
            'price_agreement_id' => $agreement->id, 'price_reference' => Arr::only($reference, ['unit_price', 'currency', 'market', 'observed_on', 'source', 'provider', 'product_label']),
            'price_publication_key' => (string) Str::uuid(),
        ]]);

        return $this->outgoing($conversation, 'price-proposal:'.$agreement->id, $this->proposalText($offer, $agreement, $reference));
    }

    private function outgoing(Conversation $conversation, string $key, string $body): Message
    {
        $message = $conversation->messages()->firstOrCreate(['deduplication_key' => $key], [
            'provider' => $conversation->provider, 'account' => $conversation->account, 'direction' => 'outbound',
            'body' => $body, 'status' => 'queued', 'metadata' => ['automatic_price' => true, 'offer_id' => $conversation->context['price_offer_id'], 'actor_id' => $conversation->context['price_actor_id']],
        ]);
        $conversation->context = [...$conversation->context, 'price_review_message_id' => $message->id];
        $conversation->save();
        OutboxEvent::enqueue('deliver:'.$message->id, 'deliver', ['message_id' => $message->id]);

        return $message;
    }

    private function eligibleOffer(Offer $offer): bool
    {
        return $offer->status === 'verified' && $offer->producer->canPublishOffers() && $offer->normalized_quantity !== null
            && bccomp($offer->normalized_quantity, '0', 3) > 0 && $offer->available_until->toDateString() >= now('America/Lima')->toDateString();
    }

    private function eligibleConversation(Conversation $conversation): bool
    {
        if ($conversation->consent !== 'granted' || $conversation->human_assigned || ! $conversation->producer?->canPublishOffers()
            || $conversation->producer->phone_lookup !== $conversation->phone_lookup || ! app(RegisteredContactPolicy::class)->accepts($conversation)) {
            return false;
        }
        $probe = new Message(['direction' => 'outbound', 'provider' => $conversation->provider, 'account' => $conversation->account, 'body' => 'Propuesta de precio', 'metadata' => []]);
        $probe->setRelation('conversation', $conversation);

        return app(MessageDelivery::class)->blockedReason($probe) === null;
    }

    private function verifier(Offer $offer): ?User
    {
        $history = $offer->histories()->where('offer_version', $offer->version)->whereIn('action', ['status_changed', 'market_variety_completed'])->get()
            ->first(fn ($history): bool => $history->action === 'market_variety_completed' || ($history->changes['status']['after'] ?? null) === 'verified');
        $actor = $history?->actor;

        return $actor?->hasPermission('manage-offers') && $actor->hasPermission('manage-lots') ? $actor : null;
    }

    private function followsSummary(Conversation $conversation, Message $message): bool
    {
        $summary = $conversation->messages()->where('direction', 'outbound')->find($conversation->context['price_review_message_id'] ?? null);

        return $summary !== null && ($conversation->provider === 'fake' || in_array($summary->status, ['accepted', 'sent', 'delivered', 'read'], true))
            && $message->id > $summary->id && $message->received_at->timestamp >= $summary->created_at->timestamp;
    }

    private function classificationText(Conversation $conversation): string
    {
        $options = collect($conversation->context['price_choices'])->values()->map(fn (array $choice, int $index): string => ($index + 1).' '.$choice['label'])->implode("\n");

        return 'Tu oferta #'.$conversation->context['price_offer_id'].' está verificada. Para calcular el precio de mercado falta identificar la variedad; este dato no estaba en tu ficha.'
            ."\n".$options."\nResponde el número de tu variedad. Si no lo sabes, escribe AYUDA. Después te mostraremos el precio y el total antes de confirmar. VENDER inicia otra oferta; PRECIO retoma esta propuesta desde el menú.";
    }

    private function proposalText(Offer $offer, OfferPriceAgreement $agreement, array $reference): string
    {
        return 'Precio propuesto para tu oferta #'.$offer->id.': '.$offer->product->name.'.'
            ."\n".$this->quantity($offer).' '.$offer->baseUnit->code.' × S/ '.$this->decimal($agreement->net_unit_price).' = S/ '.Money::format(Money::line($offer->normalized_quantity, $agreement->net_unit_price)).'.'
            ."\nReferencia: ".$reference['product_label'].' · '.$reference['market'].'.'
            ."\nFecha del precio: ".CarbonImmutable::parse($reference['observed_on'])->format('d/m/Y').'. Fuente: '.$reference['source']
            ."\nEs una referencia mayorista; puede diferir del precio en tu localidad. Sin descuentos al productor; transporte a cargo de la plataforma, sin cobro al productor."
            ."\nPropuesta válida hasta el ".$agreement->valid_until->setTimezone('America/Lima')->format('d/m/Y').'.'
            ."\n\n1 Aceptar precio y publicar para compradores\n2 No acepto; revisar con un coordinador\nResponde 1 o CONFIRMAR para aceptar. También puedes escribir AYUDA. VENDER inicia otra oferta; PRECIO retoma esta propuesta desde el menú. La publicación no garantiza una venta.";
    }

    private function quantity(Offer $offer): string
    {
        return $this->decimal($offer->normalized_quantity);
    }

    private function decimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function evidence(Message $message, string $decision): string
    {
        return 'Decisión del productor: '.$decision.'. Mensaje #'.$message->id.'; canal '.$message->provider.'/'.$message->conversation->channel
            .'; recibido '.$message->received_at->toIso8601String().'; SHA256 '.hash('sha256', $message->body).'.';
    }
}
