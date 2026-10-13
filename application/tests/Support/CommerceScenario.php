<?php

namespace Tests\Support;

use App\Models\Buyer;
use App\Models\CollectionEvent;
use App\Models\CollectionPoint;
use App\Models\CollectionReceipt;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PhysicalLot;
use App\Models\PricingPolicy;
use App\Models\ProducerSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CollectionService;
use App\Services\LogisticsService;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\SettlementService;
use Illuminate\Support\Str;

class CommerceScenario
{
    public User $buyer;

    public User $staff;

    public User $admin;

    public Order $order;

    public CollectionPoint $point;

    public function __construct(string $quantity = '60.000', string $gross = '5.000000', string $cost = '0.100000')
    {
        $this->buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $this->buyer->id]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->admin = User::factory()->withRole('admin')->create();
        $offer = Offer::factory()->published($gross, $cost)->create();
        $lot = Lot::factory()->withOffer($offer, '80.000')->create(['status' => 'published', 'published_at' => now()]);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $quote = app(PricingService::class)->issue($this->buyer, $lot, self::key(['quantity' => $quantity, 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético para pruebas de comercio', 'payment_method' => 'coordinated']));
        $this->order = app(CheckoutService::class)->confirm($this->buyer, $quote, self::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
        $this->point = CollectionPoint::factory()->create(['base_unit_id' => $lot->base_unit_id, 'location_id' => $lot->location_id, 'capacity' => '100.000']);
    }

    public static function pickups(Order $order): array
    {
        return $order->items->flatMap(fn ($item) => $item->allocations)->mapWithKeys(fn ($allocation) => [$allocation->id => ['address' => 'Punto de recojo sintético de la oferta '.$allocation->portion->offer_id, 'at' => now('America/Lima')->addDay()->setTime(8, 0)->format('Y-m-d\TH:i')]])->all();
    }

    public static function address(): array
    {
        return ['recipient' => 'Receptor de prueba', 'phone' => '51900000001', 'department' => 'Puno', 'province' => 'Puno', 'district' => 'Puno', 'street' => 'Dirección sintética número 123', 'reference' => 'Referencia sintética para pruebas', 'latitude' => '-15.840200', 'longitude' => '-70.021900'];
    }

    public static function key(array $data = []): array
    {
        return ['key' => (string) Str::uuid(), ...$data];
    }

    public function schedule(): CollectionEvent
    {
        return app(CollectionService::class)->schedule($this->staff, $this->order, self::key(['pickups' => self::pickups($this->order), 'version' => $this->order->fresh()->version, 'point_id' => $this->point->id, 'starts_at' => now('America/Lima')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('America/Lima')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'), 'location_confirmed' => '1', 'reason' => 'Ubicación y acceso sintéticos confirmados con el productor']));
    }

    public function receive(CollectionEvent $event, string $quantity = '60.000'): CollectionReceipt
    {
        return app(LogisticsService::class)->receipt($this->staff, $event, self::key(['allocation_id' => $event->items->sole()->order_allocation_id, 'expected_quantity' => $quantity, 'received_quantity' => $quantity, 'accepted_quantity' => $quantity, 'rejected_quantity' => '0.000', 'quality_status' => 'accepted', 'arrived_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Pesaje sintético verificado por coordinación']));
    }

    public function consolidate(CollectionReceipt $receipt): PhysicalLot
    {
        return app(LogisticsService::class)->consolidate($this->staff, $this->order, self::key(['portions' => [['receipt_id' => $receipt->id, 'quantity' => $receipt->accepted_quantity]]]));
    }

    public function ship(PhysicalLot $physical): Shipment
    {
        return app(LogisticsService::class)->shipment($this->staff, $this->order, self::key(['portions' => $physical->items->map(fn ($i): array => ['physical_item_id' => $i->id, 'quantity' => $i->quantity])->all(), 'cost_minor' => 0, 'scheduled_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Retiro sintético por un responsable verificado']));
    }

    public function deliver(Shipment $shipment, string $quantity = '60.000'): Shipment
    {
        $shipment = app(LogisticsService::class)->dispatch($this->staff, $shipment, self::key(['version' => $shipment->version, 'notes' => 'Salida sintética revisada por coordinación']));

        return app(LogisticsService::class)->outcome($this->staff, $shipment, self::key(['version' => $shipment->version, 'item_id' => $shipment->items->sole()->id, 'outcome' => 'delivered', 'quantity' => $quantity, 'notes' => 'Entrega sintética comprobada con el comprador']));
    }

    public function payment(?string $amount = null, array $extra = []): Payment
    {
        return app(PaymentService::class)->declare($this->buyer, $this->order, self::key(['method_id' => PaymentMethod::query()->where('code', 'yape')->sole()->id, 'amount' => $amount ?? Money::format($this->order->fresh()->total_minor), 'operation_reference' => 'SIM-'.Str::uuid(), 'occurred_at' => now('America/Lima')->format('Y-m-d\TH:i'), ...$extra]));
    }

    public function confirm(Payment $payment): Payment
    {
        $payment = app(PaymentService::class)->reviewPayment($this->admin, $payment, self::key(['version' => $payment->version, 'result' => 'under_review', 'notes' => 'Revisión sintética del movimiento recibido']));

        return app(PaymentService::class)->reviewPayment($this->admin, $payment, self::key(['version' => $payment->version, 'result' => 'confirmed', 'notes' => 'Ingreso sintético verificado en la cuenta receptora']));
    }

    public function settlement(): ProducerSettlement
    {
        return app(SettlementService::class)->calculate($this->admin, $this->order, self::key(['producer_id' => $this->order->items->sole()->allocations->sole()->portion->offer->producer_id]));
    }

    public function approve(ProducerSettlement $settlement): ProducerSettlement
    {
        return app(SettlementService::class)->approve($this->admin, $settlement, self::key(['notes' => 'Peso aceptado y acuerdo sintético revisados']));
    }
}
