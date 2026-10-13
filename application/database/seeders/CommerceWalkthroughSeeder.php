<?php

namespace Database\Seeders;

use App\Models\Lot;
use App\Models\OperationKey;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PricingPolicy;
use App\Models\TracePublication;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CollectionService;
use App\Services\Commerce;
use App\Services\LogisticsService;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\SettlementService;
use App\Services\TraceabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit local walkthrough; never invoked by DatabaseSeeder or startup. No external transfers or messages. */
class CommerceWalkthroughSeeder extends Seeder
{
    public const QUOTE_KEY = '189a3c17-ffcb-44ad-b461-c2ff090e16a1';

    private function data(array $data = []): array
    {
        return ['key' => (string) Str::uuid(), ...$data];
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('commerce.live_enabled')) {
            throw new RuntimeException('La demostración requiere local/testing con comercio real desactivado.');
        }
        $this->call(PaymentMethodSeeder::class);
        $buyer = User::query()->where('email', 'comprador@agroconecta.test')->firstOrFail();
        $staff = User::query()->where('email', 'coordinador@agroconecta.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@agroconecta.test')->firstOrFail();
        $order = app(Commerce::class)->transaction($admin, 'confirm-payments', function () use ($buyer, $staff, $admin): Order {
            $previous = OperationKey::query()->where('actor_id', $buyer->id)->where('operation', 'quote')->where('key', self::QUOTE_KEY)->first();
            if ($previous) {
                return Order::query()->where('quote_id', $previous->resource_id)->firstOrFail();
            }
            $lot = Lot::query()->where('code', 'L-CNNSF4LO')->firstOrFail();
            Commerce::ensure($lot->portions->every(fn ($p): bool => str_contains($p->offer->producer->full_name, 'Sintética')), 'lot', 'Esta demostración utiliza exclusivamente el lote sintético L-CNNSF4LO.');
            $policy = PricingPolicy::query()->where('base_unit_id', $lot->base_unit_id)->where('synthetic', true)->where('active', true)->where('fee_unit_price', '0.100000')->where('logistics_minor', 0)->where('tax_bps', 0)->firstOrFail();
            $quote = app(PricingService::class)->issue($buyer, $lot, $this->data(['key' => self::QUOTE_KEY, 'quantity' => '25.125', 'policy_id' => $policy->id, 'delivery_address' => ['recipient' => 'Receptor sintético', 'phone' => '51900000001', 'department' => 'Puno', 'province' => 'Puno', 'district' => 'Puno', 'street' => 'Dirección sintética número 123', 'reference' => 'Referencia de prueba sin traslado real', 'latitude' => '-15.840200', 'longitude' => '-70.021900'], 'delivery_details' => 'DEMOSTRACIÓN SINTÉTICA: retiro local de prueba, sin mercancía ni pago reales.', 'payment_method' => 'yape']));
            $order = app(CheckoutService::class)->confirm($buyer, $quote, $this->data(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
            $collection = app(CollectionService::class);
            $point = $collection->point($staff, $this->data(['name' => 'Acopio demostrativo de Puno', 'description' => 'Punto sintético para observar las fases 13 a 16', 'address' => 'Dirección de demostración · No corresponde a un local real', 'location_id' => $lot->location_id, 'base_unit_id' => $lot->base_unit_id, 'capacity' => '1000.000', 'opens_at' => '00:00', 'closes_at' => '23:59']));
            $from = CarbonImmutable::now('America/Lima')->addMinutes(5);
            if ($from->hour === 23) {
                $from = $from->addDay()->setTime(9, 0);
            }
            $until = $from->addMinutes(30);
            $event = $collection->schedule($staff, $order, $this->data(['version' => $order->version, 'pickups' => $order->items->flatMap(fn ($item) => $item->allocations)->mapWithKeys(fn ($a) => [$a->id => ['address' => 'Recojo de demostración sin traslado real', 'at' => $from->subMinute()->format('Y-m-d\TH:i')]])->all(), 'point_id' => $point->id, 'starts_at' => $from->format('Y-m-d\TH:i'), 'ends_at' => $until->format('Y-m-d\TH:i'), 'location_confirmed' => '1', 'reason' => 'Planificación sintética para demostrar el recorrido local completo']));
            $logistics = app(LogisticsService::class);
            $portions = [];
            foreach ($event->items as $item) {
                $quantity = $item->expected_quantity;
                $receipt = $logistics->receipt($staff, $event, $this->data(['allocation_id' => $item->order_allocation_id, 'expected_quantity' => $quantity, 'received_quantity' => $quantity, 'accepted_quantity' => $quantity, 'rejected_quantity' => '0.000', 'quality_status' => 'accepted', 'arrived_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Pesaje simulado para la demostración; no acredita mercancía real']));
                $portions[] = ['receipt_id' => $receipt->id, 'quantity' => $quantity];
            }
            $physical = $logistics->consolidate($staff, $order, $this->data(['portions' => $portions]));
            $payments = app(PaymentService::class);
            $payment = $payments->declare($buyer, $order, $this->data(['method_id' => PaymentMethod::query()->where('code', 'yape')->sole()->id, 'amount' => Money::format($order->total_minor), 'operation_reference' => 'SIM-DEMO-FASE16-INGRESO', 'occurred_at' => now('America/Lima')->format('Y-m-d\TH:i')]));
            $payment = $payments->reviewPayment($admin, $payment, $this->data(['version' => $payment->version, 'result' => 'under_review', 'notes' => 'Revisión sintética sin comprobante bancario ni ingreso real']));
            $payments->reviewPayment($admin, $payment, $this->data(['version' => $payment->version, 'result' => 'confirmed', 'notes' => 'Confirmación simulada del importe para probar la conciliación']));
            $shipment = $logistics->shipment($staff, $order, $this->data(['portions' => $physical->items->map(fn ($i): array => ['physical_item_id' => $i->id, 'quantity' => $i->quantity])->all(), 'cost_minor' => 0, 'scheduled_at' => now('America/Lima')->format('Y-m-d\TH:i'), 'notes' => 'Retiro sintético sin transporte ni costo reales']));
            $shipment = $logistics->dispatch($staff, $shipment, $this->data(['version' => $shipment->version, 'notes' => 'Salida simulada para probar la cadena de custodia']));
            foreach ($shipment->items as $item) {
                $shipment = $logistics->outcome($staff, $shipment, $this->data(['version' => $shipment->fresh()->version, 'item_id' => $item->id, 'outcome' => 'delivered', 'quantity' => $item->quantity, 'notes' => 'Entrega simulada sin movimiento de mercancía real']));
            }
            $settlements = app(SettlementService::class);
            $producerIds = app(CheckoutService::class)->allocations($order)->with('portion.offer')->get()->map(fn ($a) => $a->portion->offer->producer_id)->unique();
            foreach ($producerIds as $producerId) {
                $settlement = $settlements->calculate($admin, $order, $this->data(['producer_id' => $producerId]));
                $settlement = $settlements->approve($admin, $settlement, $this->data(['notes' => 'Acuerdo y peso sintéticos revisados para la demostración']));
                if ($settlement->net_minor > 0) {
                    $payout = $settlements->authorize($admin, $settlement, $this->data(['amount' => Money::format($settlement->net_minor), 'reason' => 'Desembolso simulado para demostrar la conciliación']));
                    $settlements->outcome($admin, $payout, $this->data(['version' => $payout->version, 'result' => 'confirmed', 'operation_reference' => 'SIM-DEMO-FASE16-PRODUCTOR-'.$producerId, 'notes' => 'Confirmación sintética: no hubo transferencia a ningún productor']));
                }
            }
            $order = $settlements->close($admin, $order, $this->data(['version' => $order->fresh()->version, 'notes' => 'Cierre de demostración con todos los saldos sintéticos conciliados']));
            app(TraceabilityService::class)->publish($staff, $lot, $this->data(['physical_lot_id' => $physical->id, 'accepted' => '1']));

            return $order;
        });
        $this->command?->info('Demostración sintética: '.route('commerce.orders.show', $order));
        $publication = TracePublication::query()->whereHas('physicalLot', fn ($q) => $q->where('order_id', $order->id))->whereNull('revoked_at')->first();
        if ($publication) {
            $this->command?->info('Ficha pública de prueba: '.app(TraceabilityService::class)->publicUrl($publication->token));
        }
    }
}
