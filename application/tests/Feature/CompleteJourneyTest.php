<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Buyer;
use App\Models\CollectionPoint;
use App\Models\Conversation;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PricingPolicy;
use App\Models\Producer;
use App\Models\ProducerSettlement;
use App\Models\Quote;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\AllocationService;
use App\Services\CollectionService;
use App\Services\LogisticsService;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\MessageIngress;
use App\Services\Money;
use App\Services\OfferRegistry;
use App\Services\OperationalNotifier;
use App\Services\PaymentService;
use App\Services\ReportDocument;
use App\Services\ReportService;
use App\Services\SettlementService;
use App\Services\TraceabilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class CompleteJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function say(string $phone, string $body, ?string $id = null): Conversation
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', $id ?? (string) Str::uuid(), $phone, $body, CarbonImmutable::now());
        app(ConversationEngine::class)->process($message->conversation_id);

        return $message->conversation->fresh();
    }

    public function test_three_new_producers_sell_220_kg_from_messages_to_reconciled_close_trace_and_report(): void
    {
        config(['producers.lookup_key' => str_repeat('a', 64), 'messaging.live_enabled' => false, 'messaging.simulator_enabled' => true, 'messaging.registered_contacts_only' => false, 'commerce.live_enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class, PaymentMethodSeeder::class]);
        $staff = User::factory()->withRole('coordinator')->create();
        $admin = User::factory()->withRole('admin')->create();
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $key = fn (array $data = []): array => CommerceScenario::key($data);
        $offers = [];
        $offerService = app(OfferRegistry::class);
        $until = now('America/Lima')->addDays(3)->toDateString();
        foreach (range(1, 3) as $i) {
            $phone = '+5190000010'.$i;
            $name = 'Productor Sintético '.['Uno', 'Dos', 'Tres'][$i - 1];
            $this->assertSame('WAITING_CONSENT', $this->say($phone, 'HOLA')->state);
            $this->say($phone, 'ACEPTO');
            $this->say($phone, $name);
            $conversation = $this->say($phone, '2');
            $this->assertSame('WAITING_IDENTITY_VERIFICATION', $conversation->state);
            $this->actingAs($staff)->post(route('messaging.register', $conversation), ['full_name' => $name, 'dni' => '0000010'.$i, 'type' => 'family'])->assertSessionHasNoErrors();
            $producer = $conversation->fresh()->producer;
            $this->assertSame('pending_verification', $producer->status);
            $this->post(route('producers.verify', $producer), ['result' => 'verified', 'method' => 'field_visit', 'notes' => 'Verificación sintética para prueba integral, sin identidad real'])->assertSessionHasNoErrors();
            $this->assertSame('verified', $producer->fresh()->status);
            $this->say($phone, 'QUINUA 80KG PUNO');
            $this->say($phone, 'HOY');
            $conversation = $this->say($phone, $until);
            $this->assertSame('OFFER_REVIEW', $conversation->state);
            $confirmation = 'CONFIRMAR '.$conversation->context['token'];
            $messageId = (string) Str::uuid();
            $this->say($phone, $confirmation, $messageId);
            $this->say($phone, $confirmation, $messageId);
            $offer = Offer::query()->where('producer_id', $producer->id)->sole();
            $this->assertSame('80.000', $offer->normalized_quantity);
            $this->assertSame('simulator', $offer->channel);
            $offer = $offerService->transition($staff, $offer, ['version' => $offer->version, 'result' => 'verified', 'reason' => 'Cantidad, calidad y procedencia sintéticas revisadas']);
            $agreement = $offerService->proposeAgreement($staff, $offer, ['version' => $offer->version, 'gross_unit_price' => '5.000000', 'producer_unit_cost' => '0.500000', 'terms' => 'Acuerdo sintético: neto S/ 4.50 por kilogramo aceptado', 'valid_until' => $until]);
            $offerService->decideAgreement($staff, $offer, $agreement, ['version' => $offer->version, 'decision' => 'accepted', 'evidence' => 'Aceptación sintética registrada para verificar el recorrido']);
            $offers[] = $offerService->transition($staff, $offer, ['version' => $offer->version, 'result' => 'published', 'reason' => 'Publicación de oferta sintética con acuerdo aceptado']);
        }
        $this->assertDatabaseCount('producers', 3);
        $this->assertDatabaseCount('offers', 3);
        $aggregation = app(AggregationService::class);
        $lot = $aggregation->create($staff, ['source_key' => (string) Str::uuid(), 'reason' => 'Agrupación sintética de tres aportaciones compatibles', 'portions' => array_map(fn ($offer): array => ['offer_id' => $offer->id, 'quantity' => '80.000', 'offer_version' => $offer->version, 'agreement_id' => $offer->activeAgreement()->id], $offers)]);
        $lot = $aggregation->transition($staff, $lot, ['version' => $lot->version, 'result' => 'published', 'reason' => 'Lote sintético de 240 kg disponible para compra']);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $this->actingAs($buyer)->get('/marketplace')->assertOk()->assertSee($lot->code);
        $quoteInput = $key(['quantity' => '220.000', 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético de 220 kg para prueba integral', 'payment_method' => 'yape']);
        $response = $this->postJson(route('commerce.quotes.store', $lot), $quoteInput)->assertCreated()->assertJsonPath('data.total_minor', 112100);
        $quote = Quote::query()->findOrFail($response->json('data.id'));
        $this->postJson(route('commerce.quotes.store', $lot), $quoteInput)->assertCreated()->assertJsonPath('data.id', $quote->id);
        $reservation = $this->postJson(route('commerce.reserve', $quote), $key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]))->assertCreated()->json('id');
        $confirmation = $key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString(), 'reservation_id' => $reservation]);
        $this->postJson(route('commerce.confirm', $quote), $confirmation)->assertCreated();
        $this->postJson(route('commerce.confirm', $quote), $confirmation)->assertCreated();
        $order = Order::query()->sole();
        $this->assertSame(112100, $order->total_minor);
        $this->assertEqualsCanonicalizing(['80.000', '80.000', '60.000'], $order->items->sole()->allocations->pluck('quantity')->all());
        $this->assertSame('20.000', $lot->portions->reduce(fn (string $sum, $portion): string => bcadd($sum, app(AllocationService::class)->free($portion), 3), '0.000'));

        $point = CollectionPoint::factory()->create(['base_unit_id' => $lot->base_unit_id, 'location_id' => $lot->location_id, 'capacity' => '1000.000']);
        $event = app(CollectionService::class)->schedule($staff, $order, $key(['version' => $order->version, 'pickups' => CommerceScenario::pickups($order), 'point_id' => $point->id, 'starts_at' => now('America/Lima')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('America/Lima')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'), 'location_confirmed' => '1', 'reason' => 'Acopio sintético coordinado con tres productores']));
        $notices = OperationalNotice::query()->where('collection_event_id', $event->id)->whereNotNull('producer_id')->get();
        $this->assertCount(3, $notices);
        foreach ($notices as $notice) {
            app(OperationalNotifier::class)->deliver($notice->id);
            app(OperationalNotifier::class)->deliver($notice->id);
            $message = $notice->fresh()->message;
            $this->assertNotNull($message);
            app(MessageDelivery::class)->deliver($message->id);
            app(MessageDelivery::class)->deliver($message->id);
            app(MessageIngress::class)->receipt('fake', 'local-simulator', 'fake:'.$message->id, 'delivered', CarbonImmutable::now());
            $this->assertSame('delivered', $message->fresh()->status);
            $this->assertSame(1, $message->attempts()->count());
            $this->assertStringContainsString('PRUEBA SINTÉTICA', $message->body);
        }
        $logistics = app(LogisticsService::class);
        $portions = [];
        foreach ($event->items as $item) {
            $quantity = $item->expected_quantity;
            $receipt = $logistics->receipt($staff, $event, $key(['allocation_id' => $item->order_allocation_id, 'expected_quantity' => $quantity, 'received_quantity' => $quantity, 'accepted_quantity' => $quantity, 'rejected_quantity' => '0.000', 'quality_status' => 'accepted', 'arrived_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Pesaje sintético contrastado contra la aportación individual']));
            $portions[] = ['receipt_id' => $receipt->id, 'quantity' => $quantity];
        }
        $physical = $logistics->consolidate($staff, $order, $key(['portions' => $portions]));
        $payments = app(PaymentService::class);
        $payment = $payments->declare($buyer, $order, $key(['method_id' => PaymentMethod::query()->where('code', 'yape')->sole()->id, 'amount' => '1121.00', 'operation_reference' => 'SIM-INTEGRAL-220', 'occurred_at' => now('America/Lima')->format('Y-m-d\TH:i')]));
        $proof = $payments->proof($buyer, $payment, $key(), UploadedFile::fake()->createWithContent('simulacion.pdf', "%PDF-1.4\nPRUEBA INTEGRAL SINTETICA SIN PAGO REAL\n%%EOF"));
        $this->assertSame(112100, $payments->balance($order)['buyer_balance_minor']);
        $this->get(route('payments.proof', $proof))->assertOk();
        foreach (['under_review', 'confirmed'] as $result) {
            $payment = $payments->reviewPayment($admin, $payment, $key(['version' => $payment->fresh()->version, 'result' => $result, 'notes' => 'Conciliación sintética manual, sin transferencia bancaria']));
        }
        $this->assertSame(0, $payments->balance($order)['buyer_balance_minor']);
        $shipment = $logistics->shipment($staff, $order, $key(['portions' => $physical->items->map(fn ($item): array => ['physical_item_id' => $item->id, 'quantity' => $item->quantity])->all(), 'cost_minor' => 0, 'scheduled_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Retiro sintético de las tres aportaciones']));
        $shipment = $logistics->dispatch($staff, $shipment, $key(['version' => $shipment->version, 'notes' => 'Salida sintética con cantidades y cobro conciliados']));
        foreach ($shipment->items as $item) {
            $shipment = $logistics->outcome($staff, $shipment, $key(['version' => $shipment->fresh()->version, 'item_id' => $item->id, 'outcome' => 'delivered', 'quantity' => $item->quantity, 'notes' => 'Entrega sintética de la aportación al comprador']));
        }
        $settlements = app(SettlementService::class);
        foreach (Producer::query()->get() as $producer) {
            $settlement = $settlements->calculate($admin, $order, $key(['producer_id' => $producer->id]));
            $settlement = $settlements->approve($admin, $settlement, $key(['notes' => 'Pesos y costos sintéticos revisados por administración']));
            $payoutInput = $key(['amount' => Money::format($settlement->net_minor), 'reason' => 'Liquidación sintética completa del aporte aceptado']);
            $payout = $settlements->authorize($admin, $settlement, $payoutInput);
            $this->assertTrue($payout->is($settlements->authorize($admin, $settlement, $payoutInput)));
            $outcome = $key(['version' => $payout->version, 'result' => 'confirmed', 'operation_reference' => 'SIM-PRODUCTOR-'.$producer->id, 'notes' => 'Resultado sintético sin envío real de dinero']);
            $settlements->outcome($admin, $payout, $outcome);
            $settlements->outcome($admin, $payout, $outcome);
            $this->assertSame(0, $settlements->balance($settlement)['remaining_minor']);
        }
        $this->assertSame(99000, (int) ProducerSettlement::query()->sum('net_minor'));
        $order = $settlements->close($admin, $order, $key(['version' => $order->fresh()->version, 'notes' => 'Cierre integral sintético con entrega y saldos conciliados']));
        $this->assertSame('closed', $order->status);
        $publication = app(TraceabilityService::class)->publish($staff, $lot, $key(['physical_lot_id' => $physical->id, 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
        $trace = $this->getJson(route('api.traces.show', $publication->token))->assertOk()->assertJsonPath('data.quantity', '220.000')->assertJsonPath('data.source_count', 3)->assertJsonPath('data.synthetic', true);
        $trace->assertDontSee('00000101')->assertDontSee('51900000101')->assertDontSee('SIM-INTEGRAL-220');
        $this->get(route('trace.qr', $publication->token))->assertOk()->assertSee('<svg', false);

        $filters = ['from' => now('America/Lima')->toDateString(), 'to' => now('America/Lima')->toDateString(), 'data' => 'synthetic', 'product_id' => null, 'location_id' => null];
        $snapshot = app(ReportService::class)->snapshot($admin, $filters);
        $this->assertSame('220.000', $snapshot['metrics']['quantity_kg']);
        foreach (['orders' => 1, 'gmv_minor' => 110000, 'collected_minor' => 112100, 'producer_paid_minor' => 99000, 'buyer_due_minor' => 0, 'producer_due_minor' => 0, 'delivery_pending' => 0] as $metric => $value) {
            $this->assertSame($value, $snapshot['metrics'][$metric], $metric);
        }
        $this->actingAs($buyer)->get(route('commerce.orders.show', $order))->assertOk();
        $this->get(route('reports.index', $filters))->assertOk()->assertSee($order->code);
        $exportInput = $key([...$filters, 'report' => 'sales', 'format' => 'csv']);
        $this->post(route('reports.export'), $exportInput)->assertSessionHasNoErrors();
        $this->post(route('reports.export'), $exportInput)->assertSessionHasNoErrors();
        $export = ReportExport::query()->sole();
        $job = new GenerateReportExport($export->id);
        $job->handle(app(ReportService::class), app(ReportDocument::class));
        $job->handle(app(ReportService::class), app(ReportDocument::class));
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertStringContainsString($order->code, Storage::disk('local')->get($export->fresh()->path));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('producer_payouts', 3);
        $this->assertDatabaseCount('collection_receipts', 3);
        Http::assertNothingSent();
    }
}
