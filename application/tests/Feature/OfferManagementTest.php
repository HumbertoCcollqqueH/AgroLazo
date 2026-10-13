<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\Unit;
use App\Models\User;
use App\Services\OfferRegistry;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        Http::preventStrayRequests();
    }

    private function data(array $overrides = []): array
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();

        return [...[
            'source_key' => (string) Str::uuid(), 'producer_id' => Producer::factory()->verified()->create()->id,
            'product_id' => $product->id, 'variety_id' => $product->varieties()->value('id'),
            'quality_grade_id' => $product->qualityGrades()->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'quantity' => '80', 'unit_id' => $product->base_unit_id, 'context' => '',
            'available_from' => now('America/Lima')->toDateString(),
            'available_until' => now('America/Lima')->addWeek()->toDateString(),
            'notes' => 'Anotación privada de prueba', 'reason' => 'Registro asistido de prueba',
        ], ...$overrides];
    }

    private function create(array $overrides = []): Offer
    {
        return app(OfferRegistry::class)->create($this->staff, $this->data($overrides));
    }

    private function transition(Offer $offer, string $status): Offer
    {
        return app(OfferRegistry::class)->transition($this->staff, $offer, ['version' => $offer->version, 'result' => $status, 'reason' => 'Revisión de prueba registrada']);
    }

    private function propose(Offer $offer): OfferPriceAgreement
    {
        return app(OfferRegistry::class)->proposeAgreement($this->staff, $offer, [
            'version' => $offer->version, 'gross_unit_price' => '5.123456', 'producer_unit_cost' => '0.123455',
            'terms' => 'Retiro acordado con el productor de prueba.', 'valid_until' => now('America/Lima')->addDays(3)->toDateString(),
        ]);
    }

    private function accept(Offer $offer, OfferPriceAgreement $agreement): OfferPriceAgreement
    {
        return app(OfferRegistry::class)->decideAgreement($this->staff, $offer, $agreement, ['version' => $offer->version, 'decision' => 'accepted', 'evidence' => 'El productor de prueba confirmó estas condiciones en la visita.']);
    }

    public function test_private_routes_deny_guests_buyers_and_disabled_staff(): void
    {
        $offer = $this->create();
        $this->get(route('offers.index'))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->withRole('buyer')->create());
        foreach (['index', 'create', 'show', 'edit'] as $action) {
            $this->get(route('offers.'.$action, $offer))->assertForbidden();
        }
        $this->postJson(route('offers.store'), [])->assertForbidden();
        $this->patchJson(route('offers.update', $offer), [])->assertForbidden();
        $this->postJson(route('offers.transition', $offer), [])->assertForbidden();
        $this->postJson(route('offers.propose', $offer), [])->assertForbidden();
        $this->postJson(route('offers.upload', $offer), [])->assertForbidden();
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_assisted_registration_is_pending_exact_and_private(): void
    {
        $data = $this->data(['quantity' => '80500.125', 'unit_id' => Unit::query()->where('code', 'g')->value('id')]);
        $this->actingAs($this->staff)->post(route('offers.store'), $data)->assertSessionHasNoErrors();
        $offer = Offer::query()->sole();
        $this->assertSame('pending_verification', $offer->status);
        $this->assertSame('80.500', $offer->normalized_quantity);
        $this->assertSame('0.001000000', $offer->conversion_factor);
        $this->assertSame($this->staff->id, $offer->registered_by);
        $this->assertNotSame($data['notes'], DB::table('offers')->value('notes'));
        $this->assertNotSame($data['reason'], DB::table('offer_histories')->value('reason'));
        $this->assertArrayNotHasKey('notes', $offer->toArray());
        $this->assertFalse($offer->isAvailableForAggregation());
        $this->get(route('offers.index'))->assertOk()->assertDontSee($offer->producer->dni);
        $this->get(route('offers.show', $offer))->assertOk()->assertSee($data['notes']);
        $this->get(route('offers.create'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_source_key_retries_are_idempotent_and_conflicting_payload_is_rejected(): void
    {
        $data = $this->data();
        $first = app(OfferRegistry::class)->create($this->staff, $data);
        $again = app(OfferRegistry::class)->create($this->staff, $data);
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('offer_histories', 1);
        $this->actingAs($this->staff)->postJson(route('offers.store'), [...$data, 'quantity' => '81'])->assertUnprocessable()->assertJsonValidationErrors('source_key');
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_server_owned_fields_and_zero_values_cannot_be_injected(): void
    {
        $this->actingAs($this->staff);
        $this->postJson(route('offers.store'), $this->data(['status' => 'published', 'normalized_quantity' => '999']))->assertUnprocessable()->assertJsonValidationErrors(['status', 'normalized_quantity']);
        $this->postJson(route('offers.store'), $this->data(['requested_unit_price' => '0']))->assertUnprocessable()->assertJsonValidationErrors('requested_unit_price');
        $this->postJson(route('offers.store'), $this->data(['quantity' => '0']))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_foreign_classification_and_inactive_catalog_cannot_be_used(): void
    {
        $this->actingAs($this->staff);
        $other = ProductVariety::factory()->create();
        $this->postJson(route('offers.store'), $this->data(['variety_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('variety_id');
        $data = $this->data();
        Product::query()->findOrFail($data['product_id'])->update(['active' => false]);
        $this->postJson(route('offers.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }

    public function test_unverified_producer_can_register_assisted_but_not_verify_offer(): void
    {
        $offer = $this->create(['producer_id' => Producer::factory()->create()->id]);
        $this->actingAs($this->staff)->postJson(route('offers.transition', $offer), ['version' => 1, 'result' => 'verified', 'reason' => 'Intento de verificación'])->assertUnprocessable()->assertJsonValidationErrors('producer_id');
        $this->assertSame('pending_verification', $offer->fresh()->status);
    }

    public function test_sacks_without_approved_equivalence_stay_pending(): void
    {
        $offer = $this->create(['quantity' => '2', 'unit_id' => Unit::query()->where('code', 'saco')->value('id')]);
        $this->assertNull($offer->normalized_quantity);
        $this->actingAs($this->staff)->postJson(route('offers.transition', $offer), ['version' => 1, 'result' => 'verified', 'reason' => 'Intento sin equivalencia'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_verification_and_publication_require_current_accepted_conditions(): void
    {
        $offer = $this->transition($this->create(), 'verified');
        $this->actingAs($this->staff)->postJson(route('offers.transition', $offer), ['version' => 1, 'result' => 'published', 'reason' => 'Intento sin condiciones'])->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $agreement = $this->accept($offer, $this->propose($offer));
        $this->assertSame('5.000001', $agreement->net_unit_price);
        $published = $this->transition($offer, 'published');
        $this->assertTrue($published->isAvailableForAggregation());
        $this->assertDatabaseCount('offer_histories', 5);
        $this->get(route('offers.show', $published))->assertOk()->assertSee('5.000001');
    }

    public function test_a_new_proposal_supersedes_acceptance_and_old_proposal_cannot_be_accepted(): void
    {
        $offer = $this->transition($this->create(), 'verified');
        $old = $this->accept($offer, $this->propose($offer));
        $new = $this->propose($offer);
        $this->assertNull($offer->activeAgreement());
        $this->actingAs($this->staff)->postJson(route('offers.decide', [$offer, $old]), ['version' => 1, 'decision' => 'accepted', 'evidence' => 'Nueva constancia de prueba'])->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $this->accept($offer, $new);
        $this->assertSame($new->id, $offer->activeAgreement()->id);
    }

    public function test_economic_terms_cannot_produce_zero_or_negative_net(): void
    {
        $offer = $this->transition($this->create(), 'verified');
        $this->actingAs($this->staff)->postJson(route('offers.propose', $offer), ['version' => 1, 'gross_unit_price' => '5', 'producer_unit_cost' => '5', 'terms' => 'Condiciones de prueba inválidas', 'valid_until' => now('America/Lima')->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('producer_unit_cost');
        $this->assertDatabaseCount('offer_price_agreements', 0);
    }

    public function test_correction_creates_new_version_and_stale_write_is_rejected(): void
    {
        $offer = $this->transition($this->create(), 'needs_information');
        $data = Arr::except($this->data(['quantity' => '90']), ['source_key', 'producer_id']);
        $this->actingAs($this->staff)->patch(route('offers.update', $offer), [...$data, 'version' => 1])->assertSessionHasNoErrors();
        $offer->refresh();
        $this->assertSame(2, $offer->version);
        $this->assertSame('90.000', $offer->quantity);
        $this->assertSame('pending_verification', $offer->status);
        $this->assertSame('90', $offer->histories()->first()->changes['quantity']['after']);
        $this->patchJson(route('offers.update', $offer), [...$data, 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('offer_histories', 3);
    }

    public function test_expiration_is_idempotent_and_closes_aggregation(): void
    {
        $offer = $this->transition($this->create(), 'verified');
        $this->accept($offer, $this->propose($offer));
        $this->transition($offer, 'published');
        $this->travel(9)->days();
        $this->assertSame(1, app(OfferRegistry::class)->expire());
        $this->assertSame(0, app(OfferRegistry::class)->expire());
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertFalse($offer->fresh()->isAvailableForAggregation());
    }

    public function test_revoked_producer_or_catalog_makes_published_offer_unavailable(): void
    {
        $offer = $this->transition($this->create(), 'verified');
        $this->accept($offer, $this->propose($offer));
        $offer = $this->transition($offer, 'published');
        $offer->producer->update(['status' => 'suspended']);
        $this->assertFalse($offer->fresh()->isAvailableForAggregation());
    }

    public function test_photo_is_private_and_cannot_be_read_through_another_offer(): void
    {
        Storage::fake('local');
        $offer = $this->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jzZkAAAAASUVORK5CYII=');
        $this->actingAs($this->staff)->post(route('offers.upload', $offer), ['photo' => UploadedFile::fake()->createWithContent('quinua.png', $png), 'caption' => 'Muestra de prueba'])->assertSessionHasNoErrors();
        $photo = $offer->photos()->sole();
        Storage::disk('local')->assertExists($photo->path);
        $this->get(route('offers.photo', [$offer, $photo]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('offers.photo', [$this->create(), $photo]))->assertNotFound();
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('offers.photo', [$offer, $photo]))->assertForbidden();
    }

    public function test_price_agreements_from_another_offer_are_rejected(): void
    {
        $first = $this->transition($this->create(), 'verified');
        $second = $this->transition($this->create(), 'verified');
        $agreement = $this->propose($first);
        $this->actingAs($this->staff)->postJson(route('offers.decide', [$second, $agreement]), ['version' => 1, 'decision' => 'accepted', 'evidence' => 'Constancia del otro productor'])->assertNotFound();
    }
}
