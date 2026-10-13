<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\CollectionPoint;
use App\Models\Conversation;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\PricingPolicy;
use App\Models\Producer;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CollectionProximity;
use App\Services\CollectionService;
use App\Services\DeliveryAddress;
use App\Services\LotAvailability;
use App\Services\Messaging\MessageDelivery;
use App\Services\OperationalNotifier;
use App\Services\PricingService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Database\Seeders\PunoLaunchCatalogSeeder;
use Database\Seeders\RegionalCollectionCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class RegionalCollectionCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string|false $previousPassword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPassword = getenv('AGROLAZO_POPULATION_PASSWORD');
        putenv('AGROLAZO_POPULATION_PASSWORD=LocalOnlyFixturePassword42');
        config(['commerce.live_enabled' => false, 'commerce.show_test_labels' => false, 'commerce.test_whatsapp_enabled' => true, 'logistics.require_coordinates' => true, 'logistics.max_producer_distance_km' => 25]);
        Http::preventStrayRequests();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class, ProductionCatalogSeeder::class, PunoLaunchCatalogSeeder::class, PaymentMethodSeeder::class]);
        User::factory()->withRole('admin')->create();
        User::factory()->withRole('coordinator')->create();
    }

    protected function tearDown(): void
    {
        putenv($this->previousPassword === false ? 'AGROLAZO_POPULATION_PASSWORD' : 'AGROLAZO_POPULATION_PASSWORD='.$this->previousPassword);
        parent::tearDown();
    }

    public function test_regional_catalog_is_published_geolocated_repeatable_and_isolates_all_producer_notices(): void
    {
        $unrelated = Producer::factory()->create();
        $original = $unrelated->fresh()->getRawOriginal();
        $this->seed(RegionalCollectionCatalogSeeder::class);
        $lots = app(LotAvailability::class)->marketplace()->with('portions.offer.producer.currentLocation')->get();
        $this->assertCount(36, $lots);
        $this->assertSame(142, Producer::where('seed_batch', RegionalCollectionCatalogSeeder::BATCH)->count());
        $this->assertSame(142, Offer::count());
        $this->assertSame(6, CollectionPoint::count());
        $this->assertSame([2, 3, 4, 5, 6], $lots->map(fn ($lot) => $lot->portions->count())->unique()->sort()->values()->all());
        $this->assertSame(collect(RegionalCollectionCatalogSeeder::DISTRIBUTION)->sortKeys()->all(), $lots->groupBy('product.slug')->map->count()->sortKeys()->all());

        $proximity = app(CollectionProximity::class);
        foreach ($lots as $lot) {
            $this->assertSame('published', $lot->status);
            $this->assertSame(now('America/Lima')->toDateString(), $lot->available_from->toDateString());
            $this->assertSame(now('America/Lima')->addDays(30)->toDateString(), $lot->available_until->toDateString());
            $offers = $lot->portions->pluck('offer');
            $inspection = $proximity->inspect($offers);
            $this->assertTrue($inspection['allowed']);
            $this->assertSame([], $inspection['missing_producer_ids']);
            $this->assertLessThan(3, $inspection['max_distance_km']);
            $this->assertSame($lot->portions->count(), $offers->pluck('producer_id')->unique()->count());
            foreach ($offers as $offer) {
                $producer = $offer->producer;
                $this->assertSame('verified', $producer->status);
                $this->assertSame(RegionalCollectionCatalogSeeder::BATCH, $producer->seed_batch);
                $this->assertMatchesRegularExpression('/^\+447700900[0-9]{3}$/D', $producer->phone);
                $this->assertNotEmpty($producer->currentLocation->address);
                $this->assertNotEmpty($producer->currentLocation->community);
                $this->assertSame($offer->location_id, $producer->currentLocation->location_id);
                $this->assertNotNull($offer->activeAgreement());
            }
        }

        $this->assertSame($original, $unrelated->fresh()->getRawOriginal());
        $accounts = User::whereIn('email', array_column(RegionalCollectionCatalogSeeder::BUYERS, 'email'))->with(['buyer', 'roles'])->get();
        $this->assertCount(10, $accounts);
        foreach ($accounts as $user) {
            $this->assertTrue($user->active);
            $this->assertSame(['buyer'], $user->roles->pluck('code')->all());
            $this->assertTrue(Hash::check('LocalOnlyFixturePassword42', $user->password));
            $this->assertSame('approved', $user->buyer->status);
            $this->assertNotNull($user->buyer->approved_by);
            $address = $user->buyer->delivery_address;
            $this->assertSame($address, DeliveryAddress::validate(['delivery_address' => $address]));
            $this->assertStringNotContainsString($address['street'], DB::table('buyers')->where('id', $user->buyer->id)->value('delivery_address'));
        }

        $before = $this->populationCounts();
        $buyer = $accounts->first();
        $buyer->update(['name' => 'Razón social actualizada', 'password' => 'ModifiedBuyerPassword42']);
        $savedAddress = [...$buyer->buyer->delivery_address, 'reference' => 'Acceso actualizado por el comprador'];
        $buyer->buyer->update(['delivery_address' => $savedAddress]);
        $this->seed(RegionalCollectionCatalogSeeder::class);
        $this->assertSame($before, $this->populationCounts());
        $this->assertSame('Razón social actualizada', $buyer->fresh()->name);
        $this->assertTrue(Hash::check('ModifiedBuyerPassword42', $buyer->fresh()->password));
        $this->assertSame($savedAddress, $buyer->buyer->fresh()->delivery_address);

        $lot = $lots->first(fn ($candidate) => $candidate->portions->count() === 6);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $quote = app(PricingService::class)->issue($buyer, $lot, CommerceScenario::key([
            'quantity' => (string) $lot->available_quantity, 'policy_id' => $policy->id, 'delivery_address' => $savedAddress,
            'delivery_details' => 'Entrega en el destino guardado', 'payment_method' => 'coordinated',
        ]));
        $order = app(CheckoutService::class)->confirm($buyer, $quote, CommerceScenario::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(3)->toDateString()]));
        $notices = OperationalNotice::where('order_id', $order->id)->whereNotNull('producer_id')->get();
        $this->assertCount(6, $notices);
        $this->assertSame($lot->portions->pluck('offer.producer_id')->sort()->values()->all(), $notices->pluck('producer_id')->sort()->values()->all());
        foreach ($notices as $notice) {
            app(OperationalNotifier::class)->deliver($notice->id);
            $message = $notice->fresh()->message;
            $this->assertNotNull($message);
            $this->assertSame('fake', $message->provider);
            $this->assertStringContainsString($order->code, $message->body);
            $this->assertStringContainsString($order->requested_delivery_on->format('d/m/Y'), $message->body);
            $this->assertStringNotContainsString('PRUEBA', $message->body);
            app(MessageDelivery::class)->deliver($message->id);
            $this->assertSame('accepted', $message->fresh()->status);
        }
        $staff = User::whereHas('roles', fn ($q) => $q->where('code', 'coordinator'))->firstOrFail();
        $suggestion = app(CollectionService::class)->suggest($staff, $order);
        $this->assertNotNull($suggestion['recommended_point_id']);
        $recommended = $suggestion['points']->firstWhere('eligible', true);
        $this->assertLessThan(3, $recommended['max_distance_km']);
        Http::assertNothingSent();
    }

    public function test_existing_account_collision_aborts_without_changing_that_account_or_creating_catalog(): void
    {
        $existing = User::factory()->create(['email' => RegionalCollectionCatalogSeeder::BUYERS[0]['email']]);
        $before = $existing->fresh()->getRawOriginal();
        try {
            $this->seed(RegionalCollectionCatalogSeeder::class);
            $this->fail('The seed must reject an unrelated account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ya existe fuera de esta carga', $exception->getMessage());
        }
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame(0, Lot::count());
        $this->assertSame(0, Producer::count());
        $this->assertSame(0, Buyer::count());
        $this->assertSame(0, CollectionPoint::count());
    }

    public function test_live_commerce_blocks_population(): void
    {
        config(['commerce.live_enabled' => true]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('comercio real desactivado');
        $this->seed(RegionalCollectionCatalogSeeder::class);
    }

    public function test_password_must_be_supplied_outside_the_source_code(): void
    {
        putenv('AGROLAZO_POPULATION_PASSWORD');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGROLAZO_POPULATION_PASSWORD');
        $this->seed(RegionalCollectionCatalogSeeder::class);
    }

    private function populationCounts(): array
    {
        return [Lot::count(), Offer::count(), Producer::count(), Buyer::count(), User::count(), CollectionPoint::count(), Conversation::count(), AuditLog::count()];
    }
}
