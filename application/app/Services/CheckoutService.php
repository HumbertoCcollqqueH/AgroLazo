<?php

namespace App\Services;

use App\Models\CollectionEvent;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderAllocation;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private Commerce $commerce, private AllocationService $allocation) {}

    public function reserve(User $actor, Quote $quote, array $data): Reservation
    {
        return $this->commerce->transaction($actor, 'place-orders', function (User $actor) use ($quote, $data): Reservation {
            $data = Validator::make($data, ['key' => 'required|uuid', 'accepted' => 'required|accepted'])->validate();
            $data['quote_id'] = $quote->id;
            $this->owner($actor, $quote);

            return $this->commerce->once($actor, 'reserve', $data, function () use ($actor, $quote): Reservation {
                $this->commerce->lockLot($quote->lot);
                $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
                $at = Commerce::clock();
                if ($existing = Reservation::query()->where('quote_id', $quote->id)->first()) {
                    Commerce::ensure($existing->status === 'active' && $existing->expires_at->greaterThan($at), 'quote', 'Esta cotización ya tuvo una reserva. Solicita otra.');

                    return $existing;
                }
                $this->allocation->assertQuote($quote, $at);
                PurchaseQuantity::validate($quote->baseUnit, $quote->quantity);
                $this->assertEnabled($quote);
                $reservation = Reservation::query()->create(['quote_id' => $quote->id, 'buyer_id' => $quote->buyer_id, 'status' => 'active', 'expires_at' => $at->addMinutes((int) config('commerce.reservation_minutes'))->min($quote->expires_at)]);
                foreach ($quote->items as $item) {
                    $reservation->items()->create(['quote_item_id' => $item->id, 'lot_offer_id' => $item->lot_offer_id, 'quantity' => $item->quantity]);
                }
                $this->commerce->event($actor, 'reserved', null, $quote->lot, ['reservation_id' => $reservation->id, 'quantity' => $quote->quantity]);

                return $reservation;
            });
        });
    }

    public function confirm(User $actor, Quote $quote, array $data): Order
    {
        return $this->commerce->transaction($actor, 'place-orders', function (User $actor) use ($quote, $data): Order {
            $data = Validator::make($data, ['key' => 'required|uuid', 'accepted' => 'required|accepted', 'requested_delivery_on' => 'required|date_format:Y-m-d', 'reservation_id' => 'nullable|integer|exists:reservations,id'])->validate();
            $data['quote_id'] = $quote->id;
            $this->owner($actor, $quote);

            return $this->commerce->once($actor, 'checkout', $data, function () use ($actor, $quote, $data): Order {
                $lot = $this->commerce->lockLot($quote->lot);
                $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
                $reservation = Reservation::query()->where('quote_id', $quote->id)->lockForUpdate()->first();
                if ($existing = Order::query()->where('quote_id', $quote->id)->first()) {
                    Commerce::ensure($existing->requested_delivery_on?->toDateString() === $data['requested_delivery_on'], 'requested_delivery_on', 'Esta compra ya tiene otra fecha solicitada. Coordina cualquier cambio desde la orden.');
                    Commerce::ensure($existing->reservation_id === (isset($data['reservation_id']) ? (int) $data['reservation_id'] : null), 'reservation_id', 'La cotización ya se confirmó mediante otra operación.');

                    return $existing;
                }
                $at = Commerce::clock();
                if ($reservation) {
                    Commerce::ensure(isset($data['reservation_id']) && $reservation->id === (int) $data['reservation_id'] && $reservation->buyer_id === $quote->buyer_id && $reservation->status === 'active' && $reservation->expires_at->greaterThan($at), 'reservation_id', 'La reserva no está activa o venció. Solicita una cotización nueva.');
                } else {
                    Commerce::ensure(empty($data['reservation_id']), 'reservation_id', 'La reserva no pertenece a esta cotización.');
                }
                $this->allocation->assertQuote($quote, $at, $reservation?->id);
                PurchaseQuantity::validate($quote->baseUnit, $quote->quantity);
                $this->assertEnabled($quote);
                DeliveryAddress::validate(['delivery_address' => $quote->delivery_address]);
                $deliveryDay = $data['requested_delivery_on'];
                Commerce::ensure($quote->logistics_minor === 0, 'quote', 'Ahora la plataforma cubre el transporte. Solicita otra cotización para actualizar el total sin flete.');
                Commerce::ensure($deliveryDay > $at->setTimezone('America/Lima')->toDateString(), 'requested_delivery_on', 'Elige desde mañana para que podamos coordinar el recojo y transporte.');
                Commerce::ensure($deliveryDay >= $lot->available_from->toDateString() && $deliveryDay <= $lot->available_until->toDateString(), 'requested_delivery_on', 'Elige una fecha dentro de la disponibilidad del lote.');
                foreach ($quote->items as $item) {
                    $offer = $item->portion->offer;
                    Commerce::ensure($deliveryDay >= $offer->available_from->toDateString() && $deliveryDay <= $offer->available_until->toDateString(), 'requested_delivery_on', 'Una aportación no está disponible en esa fecha. Solicita otra cotización.');
                }
                $order = Order::query()->create(['code' => 'O-'.Str::upper(Str::random(10)), 'buyer_id' => $quote->buyer_id, 'quote_id' => $quote->id, 'reservation_id' => $reservation?->id, 'status' => 'confirmed', 'version' => 1, 'currency' => 'PEN', 'original_total_minor' => $quote->total_minor, 'total_minor' => $quote->total_minor, 'payment_method' => $quote->payment_method, 'delivery_details' => $quote->delivery_details, 'delivery_address' => $quote->delivery_address, 'requested_delivery_on' => $deliveryDay, 'synthetic' => $quote->synthetic, 'confirmed_at' => $at]);
                $orderItem = $order->items()->create(['lot_id' => $lot->id, 'quantity' => $quote->quantity, 'base_unit_id' => $lot->base_unit_id, 'product_snapshot' => ['product' => $lot->product->name, 'variety' => $lot->variety->name, 'quality' => $lot->qualityGrade->name, 'location' => app(LotAvailability::class)->geography()->get($lot->location_id)['label'] ?? $lot->location->name, 'unit' => $lot->baseUnit->code]]);
                foreach ($quote->items as $item) {
                    $orderItem->allocations()->create([...$item->only(['lot_offer_id', 'agreement_id', 'quantity', 'gross_unit_price', 'producer_unit_cost', 'terms_snapshot']), 'quote_item_id' => $item->id]);
                }
                $reservation?->update(['status' => 'converted']);
                $quote->update(['status' => 'converted']);
                $this->commerce->event($actor, 'order_confirmed', $order, $lot, ['quantity' => $quote->quantity, 'total_minor' => $quote->total_minor]);

                return $order;
            });
        });
    }

    public function cancelReservation(User $actor, Reservation $reservation, array $data): Reservation
    {
        return $this->commerce->transaction($actor, null, function (User $actor) use ($reservation, $data): Reservation {
            abort_unless($reservation->buyer->user_id === $actor->id || $actor->hasPermission('manage-commerce'), 403);
            $data = Validator::make($data, ['key' => 'required|uuid', 'reason' => 'required|string|min:5|max:1000'])->validate();
            $data['reservation_id'] = $reservation->id;

            return $this->commerce->once($actor, 'cancel-reservation', $data, function () use ($actor, $reservation, $data): Reservation {
                $this->commerce->lockLot($reservation->quote->lot);
                $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
                Commerce::ensure($reservation->status === 'active', 'reservation', 'La reserva ya no está activa.');
                $status = $reservation->expires_at->greaterThan(Commerce::clock()) ? 'cancelled' : 'expired';
                $reservation->update(['status' => $status]);
                $this->commerce->event($actor, 'reservation_'.$status, null, $reservation->quote->lot, ['reservation_id' => $reservation->id, 'reason' => $data['reason']]);

                return $reservation;
            });
        });
    }

    public function expire(): int
    {
        return $this->commerce->transaction(null, null, function (): int {
            $rows = Reservation::query()->where('status', 'active')->where('expires_at', '<=', Commerce::clock())->orderBy('id')->get();
            foreach ($rows as $reservation) {
                $this->commerce->lockLot($reservation->quote->lot);
                $reservation->update(['status' => 'expired']);
                $this->commerce->event(null, 'reservation_expired', null, $reservation->quote->lot, ['reservation_id' => $reservation->id]);
            }

            return $rows->count();
        });
    }

    public function proposeReduction(User $actor, Order $order, array $data): OrderAdjustment
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($order, $data): OrderAdjustment {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'allocation_id' => 'required|integer|exists:order_allocations,id', 'quantity' => 'required|string', 'retire' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'propose-reduction', $data, function () use ($actor, $order, $data): OrderAdjustment {
                $order = $this->lockOrder($order);
                Commerce::ensure($order->version === (int) $data['version'] && ! in_array($order->status, ['closed', 'cancelled'], true), 'version', 'La orden cambió o está cerrada.');
                $allocation = $this->allocations($order)->findOrFail($data['allocation_id']);
                $quantity = Commerce::quantity($data['quantity'], $allocation->orderItem->baseUnit->dimension);
                $this->assertReducible($allocation, $quantity);
                if (Schema::hasTable('collection_receipts') && DB::table('collection_receipts')->where('order_allocation_id', $allocation->id)->exists()) {
                    Commerce::ensure((bool) $data['retire'], 'retire', 'Las reducciones posteriores a recepción se retiran del inventario; una devolución no se revende sin inspección.');
                }
                $reduction = $order->total_minor - $this->totalAfterReduction($order, $allocation->id, $quantity);
                Commerce::ensure($reduction >= 0, 'quantity', 'El ajuste debe reducir o conservar el importe.');
                $adjustment = OrderAdjustment::query()->create(['order_id' => $order->id, 'order_version' => $order->version, 'proposed_by' => $actor->id, 'reason' => $data['reason'], 'status' => 'proposed', 'reduction_minor' => $reduction]);
                $adjustment->items()->create(['order_allocation_id' => $allocation->id, 'quantity' => $quantity, 'retire' => (bool) $data['retire'], 'reduction_minor' => $reduction]);
                $this->commerce->event($actor, 'adjustment_proposed', $order, $order->quote->lot, ['adjustment_id' => $adjustment->id, 'reduction_minor' => $reduction]);

                return $adjustment;
            });
        });
    }

    public function acceptReduction(User $actor, OrderAdjustment $adjustment, array $data): OrderAdjustment
    {
        return $this->commerce->transaction($actor, null, function (User $actor) use ($adjustment, $data): OrderAdjustment {
            abort_unless($adjustment->order->buyer->user_id === $actor->id, 403);
            $data = Validator::make($data, ['key' => 'required|uuid', 'accepted' => 'required|accepted'])->validate();
            $data['adjustment_id'] = $adjustment->id;

            return $this->commerce->once($actor, 'accept-reduction', $data, function () use ($actor, $adjustment): OrderAdjustment {
                $order = $this->lockOrder($adjustment->order);
                $adjustment = OrderAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
                Commerce::ensure($adjustment->status === 'proposed' && $adjustment->order_version === $order->version, 'adjustment', 'El ajuste cambió o ya no corresponde a esta versión de orden.');
                foreach ($adjustment->items as $item) {
                    $allocation = $this->allocations($order)->findOrFail($item->order_allocation_id);
                    $this->assertReducible($allocation, $item->quantity);
                    $allocation->update(['released_quantity' => bcadd($allocation->released_quantity, $item->quantity, 3)]);
                    if ($item->retire) {
                        $portion = $allocation->portion;
                        $portion->update(['retired_quantity' => bcadd($portion->retired_quantity, $item->quantity, 3)]);
                    }
                }
                $remaining = (string) $this->allocations($order)->selectRaw('SUM(quantity - released_quantity) AS remaining')->value('remaining');
                $order->update(['total_minor' => $order->total_minor - $adjustment->reduction_minor, 'version' => $order->version + 1, 'status' => bccomp($remaining, '0', 3) === 0 ? 'cancelled' : $order->status]);
                $adjustment->update(['status' => 'accepted', 'accepted_by' => $actor->id, 'accepted_at' => Commerce::clock()]);
                if (Schema::hasTable('collection_events')) {
                    foreach (CollectionEvent::query()->where('order_id', $order->id)->where('status', 'scheduled')->get() as $event) {
                        app(OperationalNotifier::class)->cancelCollection($event);
                        if ($order->status === 'cancelled') {
                            $event->update(['status' => 'cancelled']);
                        }
                        app(OperationalNotifier::class)->collection($event);
                    }
                }
                $this->commerce->event($actor, 'adjustment_accepted', $order, $order->quote->lot, ['adjustment_id' => $adjustment->id, 'total_minor' => $order->total_minor]);

                return $adjustment;
            });
        });
    }

    public function lockOrder(Order $order): Order
    {
        $lotIds = $order->items()->pluck('lot_id');
        $offerIds = LotOffer::query()->whereIn('lot_id', $lotIds)->pluck('offer_id');
        Offer::query()->whereKey($offerIds)->orderBy('id')->lockForUpdate()->get();
        LotOffer::query()->whereIn('offer_id', $offerIds)->orderBy('id')->lockForUpdate()->get();
        Lot::query()->whereKey($lotIds)->orderBy('id')->lockForUpdate()->get();

        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    public function allocations(Order $order): Builder
    {
        return OrderAllocation::query()->whereIn('order_item_id', $order->items()->select('id'));
    }

    private function assertReducible(OrderAllocation $allocation, string $quantity): void
    {
        $accepted = Schema::hasTable('collection_receipts')
            ? (string) DB::table('collection_receipts')->where('order_allocation_id', $allocation->id)->sum('accepted_quantity') : '0';
        $disposed = Schema::hasTable('shipment_items') ? (string) DB::table('shipment_items')->join('physical_lot_items', 'physical_lot_items.id', '=', 'shipment_items.physical_lot_item_id')->join('collection_receipts', 'collection_receipts.id', '=', 'physical_lot_items.collection_receipt_id')->where('collection_receipts.order_allocation_id', $allocation->id)->selectRaw('COALESCE(SUM(shipment_items.returned_quantity + shipment_items.lost_quantity), 0) AS disposed')->value('disposed') : '0';
        $reducible = bcadd(bcsub(bcsub($allocation->quantity, $allocation->released_quantity, 3), $accepted, 3), $disposed, 3);
        Commerce::ensure(bccomp($quantity, $reducible, 3) <= 0, 'quantity', 'Solo se puede reducir saldo no recibido, o mercancía devuelta/perdida con custodia conciliada. Resuelve primero la mercancía recibida.');
    }

    private function totalAfterReduction(Order $order, int $allocationId, string $quantity): int
    {
        $remaining = '0';
        $subtotal = 0;
        foreach ($this->allocations($order)->get() as $allocation) {
            $rest = bcsub($allocation->quantity, $allocation->released_quantity, 3);
            if ($allocation->id === $allocationId) {
                $rest = bcsub($rest, $quantity, 3);
            }
            $remaining = bcadd($remaining, $rest, 3);
            $subtotal += Money::line($rest, $allocation->gross_unit_price);
        }
        $quote = $order->quote;
        $ratio = bcdiv($remaining, $quote->quantity, 12);
        $charges = [];
        foreach (['fee', 'logistics', 'discount'] as $field) {
            $charges[$field] = (int) bcadd(bcmul((string) $quote->getAttribute($field.'_minor'), $ratio, 12), '0.5', 0);
        }
        $base = $subtotal + $charges['fee'] + $charges['logistics'] - $charges['discount'];
        $tax = (int) bcadd(bcdiv(bcmul((string) $base, (string) $quote->pricing_snapshot['tax_bps'], 0), '10000', 6), '0.5', 0);

        return $base + $tax;
    }

    private function owner(User $actor, Quote $quote): void
    {
        abort_unless($quote->buyer->user_id === $actor->id, 403);
    }

    private function assertEnabled(Quote $quote): void
    {
        Commerce::ensure($quote->synthetic ? app()->environment(['local', 'testing']) : (bool) config('commerce.live_enabled'), 'quote', 'La operación real no está habilitada.');
        Commerce::ensure($quote->pricingPolicy->active, 'quote', 'La regla fue desactivada. Solicita una cotización nueva.');
    }
}
