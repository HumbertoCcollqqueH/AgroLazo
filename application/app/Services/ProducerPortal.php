<?php

namespace App\Services;

use App\Events\InboxUpdated;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProducerPortal
{
    public static function notify(User $user, string $body, string $url, string $key): void
    {
        $url = (parse_url($url, PHP_URL_PATH) ?: '/coordination').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
        $hex = md5('agrolazo-platform:'.$user->id.':'.$key);
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        $created = DB::table('notifications')->insertOrIgnore(['id' => $id, 'type' => PlatformNotification::class,
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['body' => $body, 'url' => $url], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
        if ($created) {
            InboxUpdated::dispatch($user->id, $id);
        }
    }

    public static function team(string $body, string $url, string $key, string $permission = 'view-operations'): void
    {
        User::query()->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->where('code', $permission))
            ->each(fn (User $user) => self::notify($user, $body, $url, $key));
    }

    public function propose(Offer $offer, User $actor): ?OfferPriceAgreement
    {
        return DB::transaction(function () use ($offer, $actor): ?OfferPriceAgreement {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $user = $offer->producer->user;
            if ($offer->status !== 'verified' || ! config('market-pricing.enabled', true)
                || $offer->agreements()->where('offer_version', $offer->version)->where('valid_until', '>', now())->exists()) {
                return null;
            }
            $reference = app(AutomatedMarketPriceProvider::class)->forOffer($offer);
            if (! $reference || $reference['currency'] !== 'PEN') {
                if ($user) {
                    self::notify($user, 'Oferta #'.$offer->id.' revisada. El equipo está completando el precio o la variedad. Te avisaremos aquí cuando puedas aceptar y publicar.', route('producer.offers.show', $offer), 'price-pending:'.$offer->id.':'.$offer->version);
                }

                return null;
            }
            $observed = CarbonImmutable::parse($reference['observed_on'], 'America/Lima')->startOfDay();
            $today = CarbonImmutable::now('America/Lima')->startOfDay();
            $age = max(1, (int) config('market-pricing.max_age_days', 7));
            $until = min($offer->available_until->toDateString(), $observed->addDays($age)->toDateString());
            if ($observed->gt($today) || $observed->lt($today->subDays($age)) || $until < $offer->available_from->toDateString()) {
                return null;
            }

            return app(OfferRegistry::class)->proposeAgreement($actor, $offer, [
                'version' => $offer->version, 'gross_unit_price' => (string) $reference['unit_price'], 'producer_unit_cost' => '0',
                'valid_until' => $until, 'terms' => 'Referencia de mercado: '.$reference['product_label'].'. '.$reference['market'].'. Fecha '.$reference['observed_on'].'. Fuente '.$reference['source'].'. Referencia mayorista; puede diferir del precio en chacra. Sin descuentos al productor. Transporte cubierto por AgroLazo. Requiere tu aceptación antes de publicar.',
            ]);
        }, 3);
    }

    public function decide(User $actor, Offer $offer, OfferPriceAgreement $agreement, array $data): void
    {
        DB::transaction(function () use ($actor, $offer, $agreement, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            abort_unless($actor->active && $actor->hasRole('producer') && $actor->producer?->id === $offer->producer_id, 403);
            $agreement = $offer->agreements()->lockForUpdate()->findOrFail($agreement->id);
            $changed = app(OfferPublication::class)->decide($actor, $offer, $agreement, [
                'version' => $data['version'], 'decision' => $data['decision'],
                'evidence' => 'Decisión expresa en la cuenta autenticada del productor #'.$actor->id.' el '.now()->toIso8601String().'. Propuesta #'.$agreement->id.'.',
            ]);
            if (! $changed) {
                return;
            }
            AuditLog::record($actor, 'producer.price_decided', $offer, ['agreement_id' => $agreement->id, 'decision' => $data['decision']], 'self_service');
            self::team('Oferta #'.$offer->id.': '.($data['decision'] === 'accepted' ? 'el productor aceptó el precio y se publicó automáticamente.' : 'el productor no aceptó el precio. Revisa su propuesta desde Conversaciones.'), route('offers.show', $offer), 'producer-decision:'.$agreement->id);
        }, 3);
    }

    public static function locationConsent(User $user, Producer $producer, bool $granted): void
    {
        $latest = $producer->consents()->where('purpose', 'location_planning')->latest('id')->first();
        $decision = $granted ? 'granted' : 'declined';
        if ($latest?->decision !== $decision) {
            $producer->consents()->create(['recorded_by' => $user->id, 'purpose' => 'location_planning', 'notice_version' => 'portal-location-v1', 'channel' => 'assisted_web', 'decision' => $decision, 'recorded_at' => now()]);
        }
    }
}
