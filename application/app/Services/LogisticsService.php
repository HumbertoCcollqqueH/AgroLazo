<?php

namespace App\Services;

use App\Models\CollectionEvent;
use App\Models\CollectionReceipt;
use App\Models\LogisticsIncident;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\PhysicalLot;
use App\Models\PhysicalLotItem;
use App\Models\ReceiptPhoto;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class LogisticsService
{
    public function __construct(private Commerce $commerce, private CheckoutService $checkout) {}

    private function nonnegative(mixed $value): string
    {
        Commerce::ensure(is_string($value) && (bool) preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,3})?\z/', $value), 'quantity', 'Usa cantidades no negativas con hasta tres decimales.');

        return bcadd($value, '0', 3);
    }

    public function receipt(User $actor, CollectionEvent $event, array $data): CollectionReceipt
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($event, $data): CollectionReceipt {
            $data = Validator::make($data, ['key' => 'required|uuid', 'allocation_id' => 'required|integer|exists:order_allocations,id', 'expected_quantity' => 'required|string', 'received_quantity' => 'required|string', 'accepted_quantity' => 'required|string', 'rejected_quantity' => 'required|string', 'quality_status' => 'required|in:accepted,partial,rejected', 'arrived_at' => 'required|date_format:Y-m-d\TH:i', 'notes' => 'required|string|min:10|max:3000'])->validate();
            foreach (['expected', 'received', 'accepted', 'rejected'] as $field) {
                $data[$field.'_quantity'] = $this->nonnegative($data[$field.'_quantity']);
            }
            $data['collection_event_id'] = $event->id;

            return $this->commerce->once($actor, 'collection-receipt', $data, function () use ($actor, $event, $data): CollectionReceipt {
                $order = $this->checkout->lockOrder($event->order);
                $event = CollectionEvent::query()->lockForUpdate()->findOrFail($event->id);
                Commerce::ensure($event->status === 'scheduled' && ! in_array($order->status, ['cancelled', 'closed'], true), 'event', 'El acopio no está activo.');
                $allocation = $this->checkout->allocations($order)->findOrFail($data['allocation_id']);
                Commerce::ensure($event->items()->where('order_allocation_id', $allocation->id)->exists(), 'allocation_id', 'La aportación no pertenece al acopio.');
                $alreadyAccepted = (string) CollectionReceipt::query()->where('order_allocation_id', $allocation->id)->sum('accepted_quantity');
                $remaining = bcsub(bcsub($allocation->quantity, $allocation->released_quantity, 3), $alreadyAccepted, 3);
                Commerce::ensure(bccomp($data['expected_quantity'], $remaining, 3) <= 0 && bccomp($data['accepted_quantity'], $data['expected_quantity'], 3) <= 0, 'expected_quantity', 'La recepción excede la cantidad aún pendiente de esta aportación.');
                Commerce::ensure(bccomp($data['received_quantity'], bcadd($data['accepted_quantity'], $data['rejected_quantity'], 3), 3) === 0, 'received_quantity', 'El peso recibido debe ser igual al aceptado más el rechazado.');
                Commerce::ensure(bccomp(bcadd($data['expected_quantity'], $data['received_quantity'], 3), '0', 3) > 0, 'received_quantity', 'Registra una entrega o un faltante con cantidad esperada.');
                if ($allocation->orderItem->baseUnit->dimension === 'count') {
                    foreach (['expected', 'received', 'accepted', 'rejected'] as $field) {
                        $value = $data[$field.'_quantity'];
                        Commerce::ensure(bccomp($value, bcadd($value, '0', 0), 3) === 0, $field.'_quantity', 'Esta unidad no admite fracciones.');
                    }
                }
                $quality = bccomp($data['accepted_quantity'], '0', 3) === 0 ? 'rejected' : (bccomp($data['rejected_quantity'], '0', 3) > 0 ? 'partial' : 'accepted');
                Commerce::ensure($data['quality_status'] === $quality, 'quality_status', 'La decisión de calidad no concuerda con el peso aceptado y rechazado.');
                $arrived = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['arrived_at'], 'America/Lima')->startOfMinute();
                Commerce::ensure($arrived->lessThanOrEqualTo(Commerce::clock()) && $arrived->greaterThanOrEqualTo($order->confirmed_at->startOfMinute()), 'arrived_at', 'Registra la llegada real, posterior a la compra y no futura.');
                $receipt = CollectionReceipt::query()->create(['collection_event_id' => $event->id, 'order_allocation_id' => $allocation->id, 'recorded_by' => $actor->id, ...collect($data)->only(['expected_quantity', 'received_quantity', 'accepted_quantity', 'rejected_quantity', 'quality_status', 'notes'])->all(), 'arrived_at' => $arrived->utc()]);
                $short = bcsub($data['expected_quantity'], $data['accepted_quantity'], 3);
                if (bccomp($short, '0', 3) > 0) {
                    $this->incident($order, $allocation, 'shortage', $short, $receipt);
                }
                $excess = bcsub($data['received_quantity'], $data['expected_quantity'], 3);
                if (bccomp($excess, '0', 3) > 0) {
                    $this->incident($order, $allocation, 'excess', $excess, $receipt);
                }
                $order->update(['version' => $order->version + 1]);
                $this->commerce->event($actor, 'receipt_recorded', $order, $allocation->orderItem->lot, ['receipt_id' => $receipt->id, 'accepted_quantity' => $receipt->accepted_quantity, 'rejected_quantity' => $receipt->rejected_quantity]);

                return $receipt;
            });
        });
    }

    public function photo(User $actor, CollectionReceipt $receipt, array $data, UploadedFile $photo): ReceiptPhoto
    {
        Validator::make(['photo' => $photo], ['photo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120'])->validate();
        $path = null;
        try {
            return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($receipt, $data, $photo, &$path): ReceiptPhoto {
                $this->checkout->lockOrder($receipt->event->order);
                $data = Validator::make($data, ['key' => 'required|uuid'])->validate();
                $data['receipt_id'] = $receipt->id;
                $data['sha256'] = hash_file('sha256', $photo->getRealPath());

                return $this->commerce->once($actor, 'receipt-photo', $data, function () use ($actor, $receipt, $photo, $data, &$path): ReceiptPhoto {
                    if ($existing = ReceiptPhoto::query()->where('collection_receipt_id', $receipt->id)->where('sha256', $data['sha256'])->first()) {
                        return $existing;
                    }
                    Commerce::ensure($receipt->photos()->count() < 5, 'photo', 'Se admiten hasta cinco fotografías por recepción.');
                    $path = 'receipts/'.Str::uuid().'.'.$photo->extension();
                    Commerce::ensure(Storage::disk('local')->put($path, file_get_contents($photo->getRealPath())), 'photo', 'No se pudo guardar el archivo privado.');

                    return ReceiptPhoto::query()->create(['collection_receipt_id' => $receipt->id, 'path' => $path, 'mime' => $photo->getMimeType(), 'size' => $photo->getSize(), 'sha256' => $data['sha256'], 'recorded_by' => $actor->id]);
                });
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function consolidate(User $actor, Order $order, array $data): PhysicalLot
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($order, $data): PhysicalLot {
            $data = Validator::make($data, ['key' => 'required|uuid', 'portions' => 'required|array|min:1|max:100', 'portions.*.receipt_id' => 'required|integer|distinct|exists:collection_receipts,id', 'portions.*.quantity' => 'required|string'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'physical-lot', $data, function () use ($actor, $order, $data): PhysicalLot {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure(! in_array($order->status, ['cancelled', 'closed'], true), 'order', 'La orden está cerrada.');
                $pointId = null;
                $rows = [];
                foreach ($data['portions'] as $input) {
                    $receipt = CollectionReceipt::query()->with('event')->lockForUpdate()->findOrFail($input['receipt_id']);
                    Commerce::ensure($receipt->event->order_id === $order->id, 'portions', 'Una recepción no pertenece a esta orden.');
                    $pointId ??= $receipt->event->collection_point_id;
                    Commerce::ensure($receipt->event->collection_point_id === $pointId, 'portions', 'Consolida mercancía ubicada en el mismo punto.');
                    $quantity = Commerce::quantity($input['quantity'], $order->quote->baseUnit->dimension);
                    $used = (string) PhysicalLotItem::query()->where('collection_receipt_id', $receipt->id)->sum('quantity');
                    Commerce::ensure(bccomp(bcadd($used, $quantity, 3), $receipt->accepted_quantity, 3) <= 0, 'portions', 'No se puede consolidar más de lo aceptado ni duplicar mercancía.');
                    $rows[] = ['collection_receipt_id' => $receipt->id, 'quantity' => $quantity];
                }
                $physical = PhysicalLot::query()->create(['code' => 'PH-'.Str::upper(Str::random(10)), 'order_id' => $order->id, 'lot_id' => $order->quote->lot_id, 'collection_point_id' => $pointId, 'created_by' => $actor->id, 'status' => 'ready']);
                $physical->items()->createMany($rows);
                if ($this->ready($order)) {
                    $order->update(['status' => 'ready', 'version' => $order->version + 1]);
                }
                $this->commerce->event($actor, 'physical_lot_created', $order, $order->quote->lot, ['physical_lot_id' => $physical->id]);

                return $physical;
            });
        });
    }

    public function shipment(User $actor, Order $order, array $data): Shipment
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($order, $data): Shipment {
            $data = Validator::make($data, ['key' => 'required|uuid', 'portions' => 'required|array|min:1|max:100', 'portions.*.physical_item_id' => 'required|integer|distinct|exists:physical_lot_items,id', 'portions.*.quantity' => 'required|string', 'carrier' => 'nullable|string|max:200', 'vehicle' => 'nullable|string|max:100', 'cost_minor' => 'required|integer|min:0|max:1000000000', 'scheduled_at' => 'required|date_format:Y-m-d\TH:i', 'notes' => 'required|string|min:10|max:2000'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'shipment', $data, function () use ($actor, $order, $data): Shipment {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure(! in_array($order->status, ['cancelled', 'closed', 'delivered'], true), 'order', 'La orden ya no admite despachos.');
                DeliveryAddress::validate(['delivery_address' => $order->delivery_address]);
                $cost = (int) Shipment::query()->where('order_id', $order->id)->sum('cost_minor') + (int) $data['cost_minor'];
                if (($order->quote->pricing_snapshot['transport_paid_by'] ?? null) !== 'platform') {
                    Commerce::ensure($cost <= $order->quote->logistics_minor, 'cost_minor', 'El costo supera la logística aceptada. Requiere nuevas condiciones; no se cobra de oficio.');
                }
                $scheduled = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], 'America/Lima')->startOfMinute();
                Commerce::ensure($scheduled->greaterThanOrEqualTo(Commerce::clock()->startOfMinute()), 'scheduled_at', 'El despacho se programa desde la hora actual.');
                Commerce::ensure($order->requested_delivery_on === null || $scheduled->toDateString() <= $order->requested_delivery_on->toDateString(), 'scheduled_at', 'La salida no puede programarse después de la entrega solicitada. Coordina la incidencia con el comprador.');
                $rows = [];
                foreach ($data['portions'] as $input) {
                    $item = PhysicalLotItem::query()->with('physicalLot')->lockForUpdate()->findOrFail($input['physical_item_id']);
                    Commerce::ensure($item->physicalLot->order_id === $order->id, 'portions', 'La mercancía física pertenece a otra orden.');
                    $quantity = Commerce::quantity($input['quantity'], $order->quote->baseUnit->dimension);
                    $used = (string) ShipmentItem::query()->where('physical_lot_item_id', $item->id)->sum('quantity');
                    Commerce::ensure(bccomp(bcadd($used, $quantity, 3), bcsub($item->quantity, $item->retired_quantity, 3), 3) <= 0, 'portions', 'La cantidad supera la mercancía física disponible.');
                    $rows[] = ['physical_lot_item_id' => $item->id, 'quantity' => $quantity];
                }
                $shipment = Shipment::query()->create(['code' => 'D-'.Str::upper(Str::random(10)), 'order_id' => $order->id, 'scheduled_by' => $actor->id, 'status' => 'scheduled', 'version' => 1, 'carrier' => $data['carrier'] ?? null, 'vehicle' => $data['vehicle'] ?? null, 'cost_minor' => (int) $data['cost_minor'], 'scheduled_at' => $scheduled->utc(), 'notes' => $data['notes']]);
                $shipment->items()->createMany($rows);
                $this->shipmentEvent($actor, $shipment, 'scheduled', $data['notes']);
                $this->commerce->event($actor, 'shipment_scheduled', $order, $order->quote->lot, ['shipment_id' => $shipment->id]);

                return $shipment;
            });
        });
    }

    public function dispatch(User $actor, Shipment $shipment, array $data): Shipment
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($shipment, $data): Shipment {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'notes' => 'required|string|min:10|max:2000'])->validate();
            $data['shipment_id'] = $shipment->id;

            return $this->commerce->once($actor, 'dispatch', $data, function () use ($actor, $shipment, $data): Shipment {
                $order = $this->checkout->lockOrder($shipment->order);
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
                Commerce::ensure($shipment->status === 'scheduled' && $shipment->version === (int) $data['version'], 'version', 'El despacho cambió o ya salió.');
                Commerce::ensure($this->ready($order), 'order', 'Completa y concilia la recepción y sus incidencias antes de despachar.');
                if (Schema::hasTable('payments')) {
                    app(PaymentService::class)->assertDispatchAllowed($order);
                } else {
                    Commerce::ensure($order->synthetic, 'payment', 'Falta la comprobación de pago para un despacho real.');
                }
                $shipment->update(['status' => 'in_transit', 'version' => $shipment->version + 1, 'dispatched_at' => Commerce::clock()]);
                $order->update(['status' => 'in_transit', 'version' => $order->version + 1]);
                $this->shipmentEvent($actor, $shipment, 'dispatched', $data['notes']);
                $this->commerce->event($actor, 'shipment_dispatched', $order, $order->quote->lot, ['shipment_id' => $shipment->id]);

                return $shipment;
            });
        });
    }

    public function outcome(User $actor, Shipment $shipment, array $data): Shipment
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($shipment, $data): Shipment {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'item_id' => 'required|integer|exists:shipment_items,id', 'outcome' => 'required|in:delivered,returned,lost', 'quantity' => 'required|string', 'notes' => 'required|string|min:10|max:2000'])->validate();
            $data['shipment_id'] = $shipment->id;

            return $this->commerce->once($actor, 'shipment-outcome', $data, function () use ($actor, $shipment, $data): Shipment {
                $order = $this->checkout->lockOrder($shipment->order);
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
                Commerce::ensure($shipment->status === 'in_transit' && $shipment->version === (int) $data['version'], 'version', 'El despacho cambió o ya está conciliado.');
                $item = $shipment->items()->lockForUpdate()->findOrFail($data['item_id']);
                $quantity = Commerce::quantity($data['quantity'], $order->quote->baseUnit->dimension);
                $accounted = bcadd(bcadd($item->delivered_quantity, $item->returned_quantity, 3), $item->lost_quantity, 3);
                Commerce::ensure(bccomp(bcadd($accounted, $quantity, 3), $item->quantity, 3) <= 0, 'quantity', 'La cantidad excede el saldo en custodia de este despacho.');
                $column = $data['outcome'].'_quantity';
                $item->update([$column => bcadd($item->getAttribute($column), $quantity, 3)]);
                $this->shipmentEvent($actor, $shipment, $data['outcome'], $data['notes'], $item, $quantity);
                if ($data['outcome'] !== 'delivered') {
                    $this->incident($order, $item->physicalItem->receipt->allocation, $data['outcome'], $quantity, null, $item);
                }
                $unfinished = $shipment->items()->whereRaw('quantity > delivered_quantity + returned_quantity + lost_quantity')->exists();
                $hasFailure = $shipment->items()->whereRaw('returned_quantity + lost_quantity > 0')->exists();
                $shipment->update(['status' => $unfinished ? 'in_transit' : ($hasFailure ? 'failed' : 'delivered'), 'version' => $shipment->version + 1, 'completed_at' => $unfinished ? null : Commerce::clock()]);
                $this->updateDeliveryState($order);
                $this->commerce->event($actor, 'shipment_'.$data['outcome'], $order, $order->quote->lot, ['shipment_id' => $shipment->id, 'shipment_item_id' => $item->id, 'quantity' => $quantity]);

                return $shipment;
            });
        });
    }

    public function resolve(User $actor, LogisticsIncident $incident, array $data): LogisticsIncident
    {
        return $this->commerce->transaction($actor, 'manage-commerce', function (User $actor) use ($incident, $data): LogisticsIncident {
            $data = Validator::make($data, ['key' => 'required|uuid', 'resolution' => 'required|string|min:20|max:3000'])->validate();
            $data['incident_id'] = $incident->id;

            return $this->commerce->once($actor, 'resolve-incident', $data, function () use ($actor, $incident, $data): LogisticsIncident {
                $order = $this->checkout->lockOrder($incident->order);
                $incident = LogisticsIncident::query()->lockForUpdate()->findOrFail($incident->id);
                Commerce::ensure($incident->status === 'open', 'incident', 'La incidencia ya fue resuelta.');
                if ($incident->kind === 'shortage') {
                    $accepted = (string) CollectionReceipt::query()->where('order_allocation_id', $incident->order_allocation_id)->sum('accepted_quantity');
                    Commerce::ensure(bccomp($accepted, bcsub($incident->allocation->quantity, $incident->allocation->released_quantity, 3), 3) >= 0, 'incident', 'Completa la recepción o consigue la aceptación del ajuste por el comprador.');
                } elseif (in_array($incident->kind, ['returned', 'lost'], true)) {
                    $delivered = $this->deliveredFor($incident->allocation);
                    Commerce::ensure(bccomp($delivered, bcsub($incident->allocation->quantity, $incident->allocation->released_quantity, 3), 3) >= 0, 'incident', 'La entrega debe conciliar con un ajuste aceptado antes de cerrar la incidencia.');
                }
                $incident->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by' => $actor->id, 'resolved_at' => Commerce::clock()]);
                if ($this->ready($order) && $order->status === 'collecting') {
                    $order->update(['status' => 'ready', 'version' => $order->version + 1]);
                }
                $this->updateDeliveryState($order);
                $this->commerce->event($actor, 'incident_resolved', $order, $order->quote->lot, ['incident_id' => $incident->id]);

                return $incident;
            });
        });
    }

    public function deliveredFor(OrderAllocation $allocation): string
    {
        return (string) DB::table('shipment_items')->join('physical_lot_items', 'physical_lot_items.id', '=', 'shipment_items.physical_lot_item_id')->join('collection_receipts', 'collection_receipts.id', '=', 'physical_lot_items.collection_receipt_id')->where('collection_receipts.order_allocation_id', $allocation->id)->sum('shipment_items.delivered_quantity');
    }

    public function ready(Order $order): bool
    {
        if (in_array($order->status, ['cancelled', 'closed'], true) || LogisticsIncident::query()->where('order_id', $order->id)->where('status', 'open')->exists()) {
            return false;
        }
        foreach ($this->checkout->allocations($order)->get() as $allocation) {
            $accepted = (string) CollectionReceipt::query()->where('order_allocation_id', $allocation->id)->sum('accepted_quantity');
            if (bccomp($accepted, bcsub($allocation->quantity, $allocation->released_quantity, 3), 3) < 0) {
                return false;
            }
        }

        return true;
    }

    private function updateDeliveryState(Order $order): void
    {
        if (in_array($order->status, ['cancelled', 'closed'], true) || LogisticsIncident::query()->where('order_id', $order->id)->where('status', 'open')->exists()) {
            return;
        }
        foreach ($this->checkout->allocations($order)->get() as $allocation) {
            if (bccomp($this->deliveredFor($allocation), bcsub($allocation->quantity, $allocation->released_quantity, 3), 3) < 0) {
                return;
            }
        }
        $order->update(['status' => 'delivered', 'version' => $order->version + 1]);
        CollectionEvent::query()->where('order_id', $order->id)->where('status', 'scheduled')->update(['status' => 'completed']);
    }

    private function incident(Order $order, OrderAllocation $allocation, string $kind, string $quantity, ?CollectionReceipt $receipt = null, ?ShipmentItem $item = null): LogisticsIncident
    {
        return LogisticsIncident::query()->create(['order_id' => $order->id, 'order_allocation_id' => $allocation->id, 'collection_receipt_id' => $receipt?->id, 'shipment_item_id' => $item?->id, 'kind' => $kind, 'quantity' => $quantity, 'status' => 'open']);
    }

    private function shipmentEvent(User $actor, Shipment $shipment, string $action, string $notes, ?ShipmentItem $item = null, ?string $quantity = null): void
    {
        $shipment->events()->create(['shipment_item_id' => $item?->id, 'actor_id' => $actor->id, 'action' => $action, 'quantity' => $quantity, 'notes' => $notes, 'occurred_at' => Commerce::clock()]);
    }
}
