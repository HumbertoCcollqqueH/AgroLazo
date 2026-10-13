<?php

namespace App\Services;

use App\Models\CollectionEvent;
use App\Models\CollectionPoint;
use App\Models\Location;
use App\Models\Order;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class CollectionService
{
    public function __construct(private Commerce $commerce, private CheckoutService $checkout, private OperationalNotifier $notifier, private CollectionProximity $proximity) {}

    public function point(User $actor, array $data): CollectionPoint
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($data): CollectionPoint {
            $data = Validator::make($data, ['key' => 'required|uuid', 'name' => 'required|string|max:160', 'description' => 'required|string|min:5|max:2000', 'address' => 'required|string|min:10|max:2000', 'location_id' => 'required|integer|exists:locations,id', 'capacity' => 'required|string', 'base_unit_id' => 'required|integer|exists:units,id', 'opens_at' => 'required|date_format:H:i', 'closes_at' => 'required|date_format:H:i|after:opens_at', 'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90', 'regex:/\A-?[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'], 'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180', 'regex:/\A-?[0-9]{1,3}(?:\.[0-9]{1,6})?\z/']])->validate();
            $unit = Unit::query()->findOrFail($data['base_unit_id']);
            Commerce::ensure($unit->active && in_array($unit->dimension, ['mass', 'count'], true), 'base_unit_id', 'Selecciona una unidad base activa.');
            Commerce::ensure(Location::query()->findOrFail($data['location_id'])->isUsable(), 'location_id', 'La localidad debe estar activa con su jerarquía.');
            $data['capacity'] = Commerce::quantity($data['capacity'], $unit->dimension);

            return $this->commerce->once($actor, 'collection-point', $data, fn (): CollectionPoint => CollectionPoint::query()->create([...array_diff_key($data, ['key' => true]), 'recorded_by' => $actor->id, 'active' => true]));
        });
    }

    /** Geodesic approximation over registered points, never a road-time promise. */
    public function suggest(User $actor, Order $order): array
    {
        $this->commerce->authorizeOrder($actor, $order, true);
        $allocations = $this->checkout->allocations($order)->whereRaw('quantity > released_quantity')->with('portion.offer.producer.currentLocation')->get();
        $proximity = $this->proximity->inspect($allocations->map(fn ($a) => $a->portion->offer));
        $coordinates = $proximity['coordinates'];
        $missing = $proximity['missing_producer_ids'];
        $quantity = $allocations->reduce(fn (string $total, $allocation): string => bcadd($total, bcsub($allocation->quantity, $allocation->released_quantity, 3), 3), '0.000');
        $points = CollectionPoint::query()->where('active', true)->where('base_unit_id', $order->items()->firstOrFail()->base_unit_id)->with('location')->get()->filter(fn (CollectionPoint $point): bool => $point->location->isUsable());
        $ranked = $points->map(function (CollectionPoint $point) use ($coordinates, $missing, $quantity, $proximity): array {
            $distance = null;
            $maximum = null;
            if ($missing === [] && $coordinates !== [] && $point->latitude !== null && $point->longitude !== null) {
                $distance = 0.0;
                $maximum = 0.0;
                foreach ($coordinates as [$lat, $lng]) {
                    $leg = $this->proximity->distance([$lat, $lng], [(float) $point->latitude, (float) $point->longitude]);
                    $distance += $leg;
                    $maximum = max($maximum, $leg);
                }
            }
            $eligible = $proximity['allowed'] && $maximum !== null && $maximum <= $proximity['limit_km'] && bccomp($quantity, $point->capacity, 3) <= 0;

            return ['point' => $point, 'eligible' => $eligible, 'max_distance_km' => $maximum === null ? null : round($maximum, 2), 'approximate_total_km' => $distance === null ? null : round($distance, 2)];
        })->sort(fn ($a, $b): int => ($b['eligible'] <=> $a['eligible']) ?: (($a['max_distance_km'] ?? INF) <=> ($b['max_distance_km'] ?? INF)) ?: ($a['point']->id <=> $b['point']->id))->values();

        return ['points' => $ranked, 'recommended_point_id' => $ranked->firstWhere('eligible', true)['point']->id ?? null, 'missing_producer_ids' => $missing,
            'basis' => 'Se priorizan puntos con capacidad suficiente y productores a un máximo de '.$proximity['limit_km'].' km. Distancia en línea recta; coordinación debe confirmar acceso, horario y capacidad de la fecha elegida.'];
    }

    public function schedule(User $actor, Order $order, array $data): CollectionEvent
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($order, $data): CollectionEvent {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'point_id' => 'required|integer|exists:collection_points,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'reason' => 'required|string|min:10|max:2000', 'location_confirmed' => 'required|accepted', 'pickups' => 'required|array|min:1|max:100', 'pickups.*' => 'required|array:address,at', 'pickups.*.address' => 'required|string|min:10|max:1000', 'pickups.*.at' => 'required|date_format:Y-m-d\TH:i'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'schedule-collection', $data, function () use ($actor, $order, $data): CollectionEvent {
                $order = $this->checkout->lockOrder($order);
                $at = Commerce::clock();
                Commerce::ensure($order->version === (int) $data['version'] && in_array($order->status, ['confirmed', 'collecting'], true), 'version', 'La orden cambió o ya avanzó fuera de planificación.');
                $point = CollectionPoint::query()->lockForUpdate()->findOrFail($data['point_id']);
                $item = $order->items()->firstOrFail();
                Commerce::ensure($point->active && $point->base_unit_id === $item->base_unit_id && $point->location->isUsable(), 'point_id', 'El punto no está activo o su capacidad corresponde a otra unidad.');
                $proximity = $this->proximity->inspect($this->checkout->allocations($order)->whereRaw('quantity > released_quantity')->with('portion.offer.producer.currentLocation')->get()->map(fn ($a) => $a->portion->offer));
                Commerce::ensure($proximity['allowed'], 'point_id', $proximity['reason']);
                if (config('logistics.require_coordinates', true)) {
                    Commerce::ensure($proximity['missing_producer_ids'] === [] && $point->latitude !== null && $point->longitude !== null, 'point_id', 'Completa en el mapa la ubicación de los productores y del punto de acopio, y registra la autorización para planificar sus recojos.');
                }
                if ($point->latitude !== null && $point->longitude !== null) {
                    foreach ($proximity['coordinates'] as $coordinates) {
                        Commerce::ensure($this->proximity->distance($coordinates, [(float) $point->latitude, (float) $point->longitude]) <= $proximity['limit_km'], 'point_id', 'Este punto de acopio está demasiado lejos de un productor. Selecciona uno más cercano.');
                    }
                }
                $from = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], 'America/Lima')->startOfMinute();
                $until = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['ends_at'], 'America/Lima')->startOfMinute();
                Commerce::ensure($from->greaterThan($at) && $until->greaterThan($from) && $from->toDateString() === $until->toDateString(), 'starts_at', 'Programa una ventana futura dentro de un mismo día.');
                Commerce::ensure($order->requested_delivery_on === null || $until->toDateString() <= $order->requested_delivery_on->toDateString(), 'starts_at', 'El acopio debe estar programado a más tardar en la fecha solicitada de entrega al comprador.');
                Commerce::ensure($from->format('H:i:s') >= $point->opens_at && $until->format('H:i:s') <= $point->closes_at, 'starts_at', 'La ventana está fuera del horario del punto.');
                $lot = $item->lot;
                Commerce::ensure($from->toDateString() >= $lot->available_from->toDateString() && $until->toDateString() <= $lot->available_until->toDateString(), 'starts_at', 'La fecha debe estar dentro de la disponibilidad pactada.');
                $existing = CollectionEvent::query()->where('order_id', $order->id)->where('status', 'scheduled')->lockForUpdate()->first();
                Commerce::ensure(! $existing || ! $existing->receipts()->exists(), 'point_id', 'Ya hay recepciones. Conserva su punto y resuelve la operación antes de reprogramar.');
                $quantity = (string) $this->checkout->allocations($order)->selectRaw('SUM(quantity - released_quantity) AS required')->value('required');
                $occupied = (string) CollectionEvent::query()->where('collection_point_id', $point->id)->where('status', '<>', 'cancelled')->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc())->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))->sum('quantity');
                Commerce::ensure(bccomp(bcadd($quantity, $occupied, 3), $point->capacity, 3) <= 0, 'point_id', 'La capacidad del punto está comprometida en esa franja. Elige otro horario o punto.');
                if ($existing) {
                    $existing->update(['status' => 'cancelled']);
                    $this->notifier->cancelCollection($existing);
                }
                $event = CollectionEvent::query()->create(['order_id' => $order->id, 'collection_point_id' => $point->id, 'scheduled_by' => $actor->id, 'starts_at' => $from->utc(), 'ends_at' => $until->utc(), 'quantity' => $quantity, 'base_unit_id' => $point->base_unit_id, 'status' => 'scheduled', 'version' => (int) CollectionEvent::query()->where('order_id', $order->id)->max('version') + 1, 'reason' => $data['reason'], 'point_snapshot' => $point->only(['name', 'address', 'location_id', 'latitude', 'longitude', 'capacity', 'opens_at', 'closes_at'])]);
                foreach ($this->checkout->allocations($order)->get() as $allocation) {
                    $remaining = bcsub($allocation->quantity, $allocation->released_quantity, 3);
                    if (bccomp($remaining, '0', 3) > 0) {
                        $pickup = $data['pickups'][$allocation->id] ?? null;
                        Commerce::ensure($pickup !== null, 'pickups', 'Falta lugar y hora de recojo para una aportación de esta compra.');
                        $pickupAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $pickup['at'], 'America/Lima')->startOfMinute();
                        Commerce::ensure($pickupAt->greaterThan($at) && $pickupAt->lessThanOrEqualTo($from), 'pickups', 'El recojo debe ser futuro y anterior o igual al inicio del acopio.');
                        Commerce::ensure($pickupAt->toDateString() >= $allocation->portion->offer->available_from->toDateString(), 'pickups', 'El recojo debe respetar la disponibilidad del productor.');
                        $event->items()->create(['order_allocation_id' => $allocation->id, 'expected_quantity' => $remaining, 'pickup_address' => $pickup['address'], 'pickup_at' => $pickupAt->utc()]);
                    }
                }
                $order->update(['status' => 'collecting', 'version' => $order->version + 1]);
                $this->commerce->event($actor, 'collection_scheduled', $order, $lot, ['collection_event_id' => $event->id, 'replaces_id' => $existing?->id]);
                $this->notifier->collection($event);

                return $event;
            });
        });
    }
}
