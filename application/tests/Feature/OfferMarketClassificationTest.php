<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\User;
use App\Services\OfferRegistry;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfferMarketClassificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Product $product;

    private ProductVariety $whiteVariety;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64), 'market-pricing.enabled' => true]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $this->whiteVariety = ProductVariety::factory()->create([
            'product_id' => $this->product->id, 'code' => 'blanca', 'name' => 'Blanca',
        ]);
        Http::preventStrayRequests();
    }

    private function offer(array $attributes = []): Offer
    {
        return Offer::factory()->create([
            'product_id' => $this->product->id,
            'variety_id' => $this->product->varieties()->where('code', 'unspecified')->value('id'),
            'quality_grade_id' => $this->product->qualityGrades()->where('code', 'unspecified')->value('id'),
            'base_unit_id' => $this->product->base_unit_id, 'unit_id' => $this->product->base_unit_id,
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'registered_by' => $this->staff->id, 'status' => 'verified', ...$attributes,
        ]);
    }

    private function complete(Offer $offer, ?int $varietyId = null, ?int $version = null, string $evidence = 'El productor identificó quinua blanca en el mensaje de prueba.'): Offer
    {
        return app(OfferRegistry::class)->completeMarketVariety(
            $this->staff, $offer, $varietyId ?? $this->whiteVariety->id, $version ?? $offer->version, $evidence,
        );
    }

    private function assertRejected(Offer $offer, string $field, ?int $varietyId = null, ?int $version = null, string $evidence = 'Respuesta del productor documentada en el mensaje de prueba.'): void
    {
        $before = $offer->fresh()->getAttributes();
        try {
            $this->complete($offer, $varietyId, $version, $evidence);
            $this->fail('La clasificación debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertSame($before, $offer->fresh()->getAttributes());
            $this->assertSame(0, $offer->histories()->where('action', 'market_variety_completed')->count());
        }
    }

    public function test_completion_preserves_verified_offer_and_all_other_fields_including_past_start_date(): void
    {
        $offer = $this->offer([
            'available_from' => now('America/Lima')->subDays(3)->toDateString(),
            'available_until' => now('America/Lima')->addDays(4)->toDateString(),
            'quantity' => '20.000', 'normalized_quantity' => '20.000',
            'notes' => 'Datos originales del productor para conservar.',
            'context' => 'Cosecha verificada', 'requested_unit_price' => '9.000000',
        ]);
        $before = $offer->fresh()->getAttributes();
        $evidence = 'WhatsApp: mensaje sintético producer-123; el productor respondió 1: Blanca.';
        $updated = $this->complete($offer, evidence: $evidence);

        $this->assertSame($this->whiteVariety->id, $updated->variety_id);
        $this->assertSame(2, $updated->version);
        $this->assertSame('verified', $updated->status);
        foreach ($before as $field => $value) {
            if (! in_array($field, ['variety_id', 'version', 'updated_at'], true)) {
                $this->assertSame($value, $updated->getRawOriginal($field), 'Se debe conservar '.$field);
            }
        }
        $history = $updated->histories()->sole();
        $this->assertSame('market_variety_completed', $history->action);
        $this->assertSame($this->staff->id, $history->actor_id);
        $this->assertSame(2, $history->offer_version);
        $this->assertSame(['variety_id' => ['before' => $offer->variety_id, 'after' => $this->whiteVariety->id]], $history->changes);
        $this->assertSame($evidence, $history->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offer.market_variety_completed']);
        $this->assertDatabaseCount('offer_price_agreements', 0);
        Http::assertNothingSent();
    }

    public function test_completion_does_not_replace_an_already_specified_variety(): void
    {
        $red = ProductVariety::factory()->create(['product_id' => $this->product->id, 'code' => 'roja']);
        $offer = $this->offer(['variety_id' => $red->id]);
        $this->assertRejected($offer, 'variety_id');
    }

    public function test_only_verified_offers_can_complete_the_market_variety(): void
    {
        foreach (['pending_verification', 'needs_information', 'published', 'cancelled', 'expired'] as $status) {
            $this->assertRejected($this->offer(['status' => $status]), 'variety_id');
        }
    }

    public function test_variety_must_be_active_supported_and_belong_to_the_product(): void
    {
        $other = Product::query()->where('slug', 'papa')->firstOrFail();
        $wrongProduct = ProductVariety::factory()->create(['product_id' => $other->id, 'code' => 'blanca']);
        $unsupported = ProductVariety::factory()->create(['product_id' => $this->product->id, 'code' => 'mezclada']);
        $inactive = ProductVariety::factory()->inactive()->create(['product_id' => $this->product->id, 'code' => 'negra']);
        $offer = $this->offer();
        foreach ([$wrongProduct, $unsupported, $inactive] as $variety) {
            $this->assertRejected($offer, 'variety_id', $variety->id);
        }
    }

    public function test_disabled_automatic_pricing_does_not_complete_the_market_variety(): void
    {
        config(['market-pricing.enabled' => false]);
        $this->assertRejected($this->offer(), 'variety_id');
    }

    public function test_stale_confirmation_does_not_change_or_repeat_a_completed_classification(): void
    {
        $offer = $this->offer();
        $this->assertRejected($offer, 'version', version: 2);
        $updated = $this->complete($offer);
        try {
            $this->complete($offer, version: 1);
            $this->fail('Una respuesta de la versión anterior no debe aplicarse otra vez.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
            $this->assertSame($updated->getAttributes(), $offer->fresh()->getAttributes());
            $this->assertSame(1, $offer->histories()->count());
        }
    }

    public function test_producer_answer_evidence_is_required_and_bounded(): void
    {
        $offer = $this->offer();
        foreach (['', 'Blanca', str_repeat('a', 1001)] as $evidence) {
            $this->assertRejected($offer, 'evidence', evidence: $evidence);
        }
    }

    public function test_buyer_and_inactive_operator_cannot_complete_the_variety(): void
    {
        $offer = $this->offer();
        $before = $offer->fresh()->getAttributes();
        foreach ([User::factory()->withRole('buyer')->create(), User::factory()->withRole('coordinator')->inactive()->create()] as $actor) {
            $this->staff = $actor;
            try {
                $this->complete($offer);
                $this->fail('El usuario no debe completar la clasificación.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $offer->fresh()->getAttributes());
                $this->assertSame(0, $offer->histories()->count());
            }
        }
    }

    public function test_both_offer_and_lot_permissions_are_required(): void
    {
        $offer = $this->offer();
        $role = $this->staff->roles()->firstOrFail();
        foreach (['manage-offers', 'manage-lots'] as $code) {
            $permission = $role->permissions()->where('code', $code)->firstOrFail();
            $role->permissions()->detach($permission->id);
            try {
                $this->complete($offer);
                $this->fail('Se requieren ambos permisos.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $offer->fresh()->version);
                $this->assertSame(0, $offer->histories()->count());
            } finally {
                $role->permissions()->attach($permission->id);
            }
        }
    }

    public function test_existing_agreements_prevent_reclassification_even_on_an_old_version(): void
    {
        foreach (['proposed', 'accepted', 'rejected'] as $status) {
            $offer = $this->offer(['version' => 2]);
            OfferPriceAgreement::factory()->create([
                'offer_id' => $offer->id, 'offer_version' => 1, 'status' => $status,
                'accepted_at' => $status === 'accepted' ? now() : null,
            ]);
            $this->assertRejected($offer, 'agreement');
            $this->assertSame(1, $offer->agreements()->count());
        }
    }

    public function test_existing_lot_commitments_remain_unchanged_when_reclassification_is_rejected(): void
    {
        $offer = $this->offer();
        OfferPriceAgreement::factory()->create([
            'offer_id' => $offer->id, 'offer_version' => $offer->version,
            'status' => 'accepted', 'accepted_at' => now(),
        ]);
        $lot = Lot::factory()->withOffer($offer, '20.000')->create();
        $portion = $lot->portions()->sole();
        $before = $portion->getAttributes();
        $this->assertRejected($offer, 'agreement');
        $this->assertSame($before, $portion->fresh()->getAttributes());
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseCount('lot_offers', 1);
    }

    public function test_suspended_producer_and_expired_offer_are_rechecked(): void
    {
        $offer = $this->offer();
        $offer->producer->update(['status' => 'suspended']);
        $this->assertRejected($offer, 'producer_id');
        $expired = $this->offer([
            'available_from' => now('America/Lima')->subDays(3)->toDateString(),
            'available_until' => now('America/Lima')->subDay()->toDateString(),
        ]);
        $this->assertRejected($expired, 'available_until');
    }
}
