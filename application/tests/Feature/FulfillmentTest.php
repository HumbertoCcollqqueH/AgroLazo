<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\CollectionEvent;
use App\Models\CollectionPoint;
use App\Models\CollectionReceipt;
use App\Models\LogisticsIncident;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\PhysicalLot;
use App\Models\PricingPolicy;
use App\Models\ProducerConsent;
use App\Models\ProducerLocation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\CheckoutService;
use App\Services\CollectionService;
use App\Services\LogisticsService;
use App\Services\OperationalNotifier;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class FulfillmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $buyer;

    private User $staff;

    private Order $order;

    private CollectionPoint $point;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $this->buyer->id]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->order = $this->makeOrder();
        $this->point = CollectionPoint::factory()->create(['base_unit_id' => $this->order->quote->base_unit_id, 'location_id' => $this->order->quote->lot->location_id, 'capacity' => '100.000']);
    }

    private function key(array $data = []): array
    {
        return ['key' => (string) Str::uuid(), ...$data];
    }

    public function test_pickup_requires_a_place_and_a_time_before_collection_without_partial_scheduling(): void
    {
        $this->rejected(fn () => $this->schedule(['pickups' => []]), 'pickups');
        $pickups = CommerceScenario::pickups($this->order);
        $first = array_key_first($pickups);
        $pickups[$first]['at'] = now('America/Lima')->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');
        $this->rejected(fn () => $this->schedule(['pickups' => $pickups]), 'pickups');
        $this->assertDatabaseCount('collection_events', 0);
        $event = $this->schedule();
        $entry = $event->items->first();
        $notice = OperationalNotice::query()->where('collection_event_id', $event->id)->whereNotNull('producer_id')->firstOrFail();
        $this->assertStringContainsString('Pasaremos a recoger tu oferta #'.$entry->allocation->portion->offer_id, $notice->body);
        $this->assertStringContainsString($entry->pickup_address, $notice->body);
        $this->assertStringContainsString('08:00', $notice->body);
        $this->assertStringNotContainsString('El horario de recojo se confirma por separado', $notice->body);
    }

    public function test_platform_can_record_transport_expenses_without_charging_the_buyer_or_producer(): void
    {
        $event = $this->schedule();
        $receipt = $this->receive($event);
        $physical = $this->consolidate($receipt);
        $total = $this->order->total_minor;
        $shipment = app(LogisticsService::class)->shipment($this->staff, $this->order, $this->key([
            'portions' => [['physical_item_id' => $physical->items->sole()->id, 'quantity' => '60.000']],
            'cost_minor' => 2500, 'scheduled_at' => now('America/Lima')->format('Y-m-d\TH:i'),
            'notes' => 'Transporte pagado por la plataforma para esta entrega',
        ]));
        $this->assertSame(2500, $shipment->cost_minor);
        $this->assertSame(0, $this->order->quote->logistics_minor);
        $this->assertSame($total, $this->order->fresh()->total_minor);
    }

    private function makeOrder(array $quantities = ['80.000']): Order
    {
        $lot = null;
        foreach ($quantities as $quantity) {
            $offer = Offer::factory()->published('5.000000', '0.100000')->create([
                ...($lot?->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id']) ?? []),
                ...($lot ? ['unit_id' => $lot->base_unit_id] : []), 'quantity' => $quantity, 'normalized_quantity' => $quantity,
            ]);
            if (! $lot) {
                $lot = Lot::factory()->withOffer($offer, $quantity)->create(['status' => 'published', 'published_at' => now()]);
            } else {
                LotOffer::factory()->create(['lot_id' => $lot->id, 'offer_id' => $offer->id, 'agreement_id' => $offer->activeAgreement()->id, 'offer_version' => 1, 'quantity' => $quantity]);
            }
        }
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $quote = app(PricingService::class)->issue($this->buyer, $lot, $this->key(['quantity' => '60.000', 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético coordinado en acopio', 'payment_method' => 'coordinated']));

        return app(CheckoutService::class)->confirm($this->buyer, $quote, $this->key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
    }

    private function schedule(array $extra = []): CollectionEvent
    {
        return app(CollectionService::class)->schedule($this->staff, $this->order->fresh(), $this->key([
            'version' => $this->order->fresh()->version, 'pickups' => CommerceScenario::pickups($this->order), 'point_id' => $this->point->id,
            'starts_at' => now('America/Lima')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
            'ends_at' => now('America/Lima')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'reason' => 'Ubicación sintética y acceso confirmados con el productor', 'location_confirmed' => '1', ...$extra,
        ]));
    }

    private function receive(CollectionEvent $event, array $extra = []): CollectionReceipt
    {
        return app(LogisticsService::class)->receipt($this->staff, $event, $this->key([
            'allocation_id' => $event->items->first()->order_allocation_id, 'expected_quantity' => '60.000',
            'received_quantity' => '60.000', 'accepted_quantity' => '60.000', 'rejected_quantity' => '0.000',
            'quality_status' => 'accepted', 'arrived_at' => now('America/Lima')->format('Y-m-d\TH:i'),
            'notes' => 'Pesaje y calidad sintéticos registrados por coordinación', ...$extra,
        ]));
    }

    private function rejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('La operación debía rechazar '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function consolidate(CollectionReceipt $receipt, string $quantity = '60.000'): PhysicalLot
    {
        return app(LogisticsService::class)->consolidate($this->staff, $this->order, $this->key(['portions' => [['receipt_id' => $receipt->id, 'quantity' => $quantity]]]));
    }

    private function ship(PhysicalLot $physical, string $quantity = '60.000'): Shipment
    {
        return app(LogisticsService::class)->shipment($this->staff, $this->order, $this->key(['portions' => [['physical_item_id' => $physical->items->sole()->id, 'quantity' => $quantity]], 'cost_minor' => 0, 'scheduled_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Retiro sintético por responsable verificado']));
    }

    private function dispatch(Shipment $shipment): Shipment
    {
        return app(LogisticsService::class)->dispatch($this->staff, $shipment, $this->key(['version' => $shipment->version, 'notes' => 'Salida sintética comprobada con responsable']));
    }

    private function outcome(Shipment $shipment, string $outcome, string $quantity): Shipment
    {
        return app(LogisticsService::class)->outcome($this->staff, $shipment, $this->key(['version' => $shipment->fresh()->version, 'item_id' => $shipment->items->sole()->id, 'outcome' => $outcome, 'quantity' => $quantity, 'notes' => 'Constancia sintética de movimiento de mercancía']));
    }

    public function test_full_physical_chain_and_partial_delivery_preserve_origin_and_inventory(): void
    {
        $event = $this->schedule();
        $receipt = $this->receive($event);
        $physical = $this->consolidate($receipt);
        $shipment = $this->dispatch($this->ship($physical));
        $shipment = $this->outcome($shipment, 'delivered', '25.125');
        $this->assertSame('in_transit', $shipment->status);
        $this->assertSame('in_transit', $this->order->fresh()->status);
        $shipment = $this->outcome($shipment, 'delivered', '34.875');
        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('completed', $event->fresh()->status);
        $this->assertTrue($shipment->items->sole()->physicalItem->receipt->is($receipt));
        $this->assertSame('20.000', app(AllocationService::class)->free($this->order->quote->lot->portions->sole()));
        $this->actingAs($this->staff)->get(route('commerce.orders.show', $this->order))->assertOk()->assertSee($physical->code)->assertSee($shipment->code);
    }

    public function test_capacity_overlap_hours_and_stale_schedule_are_rejected(): void
    {
        $event = $this->schedule();
        $this->order = $this->makeOrder();
        $this->point = CollectionPoint::factory()->create(['base_unit_id' => $this->order->quote->base_unit_id, 'capacity' => '50.000']);
        $this->rejected(fn () => $this->schedule(), 'point_id');
        DB::table('collection_points')->where('id', $this->point->id)->update(['capacity' => '100.000', 'opens_at' => '12:00:00']);
        $this->rejected(fn () => $this->schedule(), 'starts_at');
        DB::table('collection_points')->where('id', $this->point->id)->update(['opens_at' => '00:00:00']);
        $first = $this->schedule();
        $this->rejected(fn () => app(CollectionService::class)->schedule($this->staff, $this->order, $this->key(['version' => 1, 'pickups' => CommerceScenario::pickups($this->order), 'point_id' => $this->point->id, 'starts_at' => now('America/Lima')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('America/Lima')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'), 'reason' => 'Intento sintético con versión antigua', 'location_confirmed' => '1'])), 'version');
        $otherOrder = $this->makeOrder();
        DB::table('order_items')->where('order_id', $otherOrder->id)->update(['base_unit_id' => $this->point->base_unit_id]);
        $this->order = $otherOrder;
        $this->rejected(fn () => $this->schedule(), 'point_id');
        $this->assertSame('scheduled', $first->fresh()->status);
    }

    public function test_reprogramming_cancels_old_notices_and_receipts_prevent_location_rewrite(): void
    {
        $first = $this->schedule();
        $second = $this->schedule(['starts_at' => now('America/Lima')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('America/Lima')->addDay()->setTime(11, 0)->format('Y-m-d\TH:i')]);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertTrue(OperationalNotice::query()->where('collection_event_id', $first->id)->where('status', 'cancelled')->exists());
        $this->receive($second);
        $this->rejected(fn () => $this->schedule(), 'point_id');
    }

    public function test_repeated_receipt_is_idempotent_and_cannot_exceed_order_or_duplicate_physical_stock(): void
    {
        $event = $this->schedule();
        $key = (string) Str::uuid();
        $receipt = $this->receive($event, ['key' => $key]);
        $this->assertTrue($receipt->is($this->receive($event, ['key' => $key])));
        $this->assertDatabaseCount('collection_receipts', 1);
        $this->rejected(fn () => $this->receive($event), 'expected_quantity');
        $physical = $this->consolidate($receipt);
        $this->rejected(fn () => $this->consolidate($receipt, '0.001'), 'portions');
        $this->ship($physical);
        $this->rejected(fn () => $this->ship($physical, '0.001'), 'portions');
    }

    public function test_shortage_needs_accepted_adjustment_and_never_returns_missing_kilos_to_marketplace(): void
    {
        $event = $this->schedule();
        $receipt = $this->receive($event, ['received_quantity' => '50.000', 'accepted_quantity' => '50.000']);
        $incident = LogisticsIncident::query()->sole();
        $physical = $this->consolidate($receipt, '50.000');
        $shipment = $this->ship($physical, '50.000');
        $this->rejected(fn () => $this->dispatch($shipment), 'order');
        $this->rejected(fn () => app(LogisticsService::class)->resolve($this->staff, $incident, $this->key(['resolution' => 'Intento de cerrar sin conciliar el faltante'])), 'incident');
        $adjustment = app(CheckoutService::class)->proposeReduction($this->staff, $this->order, $this->key(['version' => $this->order->fresh()->version, 'allocation_id' => $receipt->order_allocation_id, 'quantity' => '10.000', 'retire' => true, 'reason' => 'Faltante sintético confirmado en recepción']));
        app(CheckoutService::class)->acceptReduction($this->buyer, $adjustment, $this->key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
        app(LogisticsService::class)->resolve($this->staff, $incident, $this->key(['resolution' => 'Comprador aceptó el faltante y el ajuste; no existe mercancía revendible.']));
        $this->outcome($this->dispatch($shipment), 'delivered', '50.000');
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('20.000', app(AllocationService::class)->free($this->order->quote->lot->portions->sole()));
        $this->assertSame('50.000', $receipt->fresh()->accepted_quantity);
    }

    public function test_excess_is_recorded_separately_and_requires_disposition_before_dispatch(): void
    {
        $event = $this->schedule();
        $receipt = $this->receive($event, ['received_quantity' => '65.000', 'rejected_quantity' => '5.000', 'quality_status' => 'partial']);
        $incident = LogisticsIncident::query()->sole();
        $this->assertSame('excess', $incident->kind);
        $this->assertSame('5.000', $incident->quantity);
        $shipment = $this->ship($this->consolidate($receipt));
        $this->rejected(fn () => $this->dispatch($shipment), 'order');
        app(LogisticsService::class)->resolve($this->staff, $incident, $this->key(['resolution' => 'Los cinco kilos excedentes se devolvieron al productor con constancia sintética.']));
        $this->assertSame('in_transit', $this->dispatch($shipment)->status);
    }

    public function test_transport_loss_stays_consumed_and_can_be_resolved_with_buyer_adjustment(): void
    {
        $receipt = $this->receive($this->schedule());
        $shipment = $this->dispatch($this->ship($this->consolidate($receipt)));
        $shipment = $this->outcome($shipment, 'delivered', '50.000');
        $shipment = $this->outcome($shipment, 'lost', '10.000');
        $this->assertSame('failed', $shipment->status);
        $allocation = $receipt->allocation;
        $base = ['version' => $this->order->fresh()->version, 'allocation_id' => $allocation->id, 'quantity' => '10.000', 'reason' => 'Pérdida de transporte comprobada y comunicada'];
        $this->rejected(fn () => app(CheckoutService::class)->proposeReduction($this->staff, $this->order, $this->key([...$base, 'retire' => false])), 'retire');
        $adjustment = app(CheckoutService::class)->proposeReduction($this->staff, $this->order, $this->key([...$base, 'retire' => true]));
        app(CheckoutService::class)->acceptReduction($this->buyer, $adjustment, $this->key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
        app(LogisticsService::class)->resolve($this->staff, LogisticsIncident::query()->sole(), $this->key(['resolution' => 'Pérdida documentada; comprador aceptó cantidad e importe reducido.']));
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('20.000', app(AllocationService::class)->free($allocation->portion));
        $this->assertSame('60.000', $receipt->accepted_quantity, 'La liquidación del productor conserva el peso aceptado antes de la pérdida.');
    }

    public function test_receipt_balance_quality_and_foreign_allocation_are_enforced(): void
    {
        $event = $this->schedule();
        $this->rejected(fn () => $this->receive($event, ['accepted_quantity' => '59.000']), 'received_quantity');
        $this->rejected(fn () => $this->receive($event, ['quality_status' => 'rejected']), 'quality_status');
        $other = $this->makeOrder();
        $this->actingAs($this->staff)->post(route('fulfillment.receipts', $event), $this->key(['allocation_id' => app(CheckoutService::class)->allocations($other)->sole()->id, 'expected_quantity' => '1', 'received_quantity' => '1', 'accepted_quantity' => '1', 'rejected_quantity' => '0', 'quality_status' => 'accepted', 'arrived_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Recepción de una orden ajena al acopio']))->assertNotFound();
        $this->assertDatabaseCount('collection_receipts', 0);
    }

    public function test_only_consented_coordinates_participate_in_point_suggestions(): void
    {
        $producer = $this->order->quote->items->sole()->portion->offer->producer;
        ProducerLocation::factory()->create(['producer_id' => $producer->id, 'location_id' => $this->order->quote->items->sole()->portion->offer->location_id, 'latitude' => '-15.000000', 'longitude' => '-70.000000', 'valid_until' => null]);
        DB::table('collection_points')->where('id', $this->point->id)->update(['latitude' => '-15.000000', 'longitude' => '-70.000000']);
        $withoutConsent = app(CollectionService::class)->suggest($this->staff, $this->order);
        $this->assertSame([$producer->id], $withoutConsent['missing_producer_ids']);
        $this->assertNull($withoutConsent['points']->first()['approximate_total_km']);
        ProducerConsent::factory()->create(['producer_id' => $producer->id, 'purpose' => 'location_planning', 'decision' => 'granted']);
        $withConsent = app(CollectionService::class)->suggest($this->staff, $this->order);
        $this->assertSame([], $withConsent['missing_producer_ids']);
        $this->assertSame(0.0, $withConsent['points']->first()['approximate_total_km']);
    }

    public function test_operational_notices_are_individual_and_database_delivery_is_idempotent(): void
    {
        $this->order = $this->makeOrder(['20.000', '40.000']);
        $this->point = CollectionPoint::factory()->create(['base_unit_id' => $this->order->quote->base_unit_id]);
        $event = $this->schedule();
        $notices = OperationalNotice::query()->where('collection_event_id', $event->id)->get();
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('20 '.$this->order->quote->baseUnit->code, $notices[0]->body);
        $this->assertStringNotContainsString('40 '.$this->order->quote->baseUnit->code, $notices[0]->body);
        $this->assertStringContainsString('40 '.$this->order->quote->baseUnit->code, $notices[1]->body);
        app(OperationalNotifier::class)->deliver($notices[0]->id);
        $this->assertSame('blocked', $notices[0]->fresh()->status);
        $buyerNotice = OperationalNotice::query()->where('order_id', $this->order->id)->whereNotNull('user_id')->firstOrFail();
        app(OperationalNotifier::class)->deliver($buyerNotice->id);
        app(OperationalNotifier::class)->deliver($buyerNotice->id);
        $this->assertSame(1, DB::table('notifications')->where('id', $buyerNotice->notification_uuid)->count());
        Http::assertNothingSent();
    }

    public function test_receipt_photos_are_private_and_buyers_cannot_record_logistics(): void
    {
        Storage::fake('local');
        $receipt = $this->receive($this->schedule());
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6V1cAAAAASUVORK5CYII=');
        $upload = UploadedFile::fake()->createWithContent('receipt.png', $png);
        $photo = app(LogisticsService::class)->photo($this->staff, $receipt, $this->key(), $upload);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertStringNotContainsString($photo->path, DB::table('receipt_photos')->where('id', $photo->id)->value('path'));
        $this->actingAs($this->buyer)->get(route('fulfillment.photo', $photo))->assertForbidden();
        $this->actingAs($this->staff)->get(route('fulfillment.photo', $photo))->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->actingAs($this->buyer)->get(route('fulfillment.points'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('fulfillment.points'))->assertOk();
    }
}
