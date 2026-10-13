<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CollectionReceipt;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Order;
use App\Models\PhysicalLot;
use App\Models\PhysicalLotItem;
use App\Models\ShipmentItem;
use App\Models\TracePublication;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TraceabilityService
{
    public function __construct(private Commerce $commerce, private CheckoutService $checkout) {}

    public function publicUrl(string $token): string
    {
        return rtrim(config('app.url'), '/').'/trace/'.$token;
    }

    private function region(Location $location): string
    {
        $visited = [];
        while (! in_array($location->type, ['department', 'country'], true) && $location->parent_id !== null && ! isset($visited[$location->id])) {
            $visited[$location->id] = true;
            $location = $location->parent;
        }

        return in_array($location->type, ['department', 'country'], true) ? $location->name : 'Origen registrado en Perú';
    }

    /** This projection is shared by the preview and publication; it never accepts public text from a request. */
    public function preview(User $actor, Lot $lot, ?PhysicalLot $physical = null): array
    {
        abort_unless($actor->active && $actor->hasPermission('manage-traceability'), 403);
        Commerce::ensure($physical === null || $physical->lot_id === $lot->id, 'physical_lot_id', 'El lote físico debe pertenecer al lote comercial.');
        $snapshot = [
            'schema_version' => 1,
            'kind' => $physical ? 'physical_lot' : 'commercial_lot',
            'code' => $physical?->code ?? $lot->code,
            'product' => $lot->product->name,
            'variety' => $lot->variety->name,
            'quality' => $lot->qualityGrade->name,
            'region' => $this->region($lot->location),
            'unit' => $lot->baseUnit->code,
            'recorded_at' => Commerce::clock()->toIso8601String(),
            'physical_receipt_confirmed' => $physical !== null,
            'synthetic' => $physical?->order->synthetic ?? ! config('commerce.live_enabled'),
        ];
        if (! $physical) {
            $portions = $lot->portions()->whereRaw('quantity > released_quantity + retired_quantity')->with('offer')->get();
            $quantity = $portions->reduce(fn (string $sum, $p): string => bcadd($sum, bcsub($p->quantity, bcadd($p->released_quantity, $p->retired_quantity, 3), 3), 3), '0.000');

            return [...$snapshot, 'quantity' => $quantity, 'source_count' => $portions->pluck('offer.producer_id')->unique()->count(), 'milestones' => [
                ['event' => 'commercial_composition', 'date' => $lot->created_at->toDateString(), 'quantity' => $quantity],
            ]];
        }
        $items = $physical->items()->with('receipt.allocation.portion.offer')->get();
        $quantity = $items->reduce(fn (string $sum, $i): string => bcadd($sum, $i->quantity, 3), '0.000');
        $receipts = $items->pluck('receipt')->unique('id');
        $movements = ShipmentItem::query()->whereIn('physical_lot_item_id', $items->pluck('id'))->with('shipment')->get();
        $milestones = [['event' => 'physical_consolidation', 'date' => $physical->created_at->toDateString(), 'quantity' => $quantity]];
        foreach ($movements as $movement) {
            foreach (['delivered' => 'delivered_quantity', 'returned' => 'returned_quantity', 'lost' => 'lost_quantity'] as $event => $field) {
                if (bccomp($movement->$field, '0', 3) > 0) {
                    $milestones[] = ['event' => $event, 'date' => $movement->updated_at->toDateString(), 'quantity' => $movement->$field];
                }
            }
        }

        return [...$snapshot, 'quantity' => $quantity, 'source_count' => $receipts->pluck('allocation.portion.offer.producer_id')->unique()->count(), 'milestones' => $milestones];
    }

    public function publish(User $actor, Lot $lot, array $data): TracePublication
    {
        return $this->commerce->transaction($actor, 'manage-traceability', function (User $actor) use ($lot, $data): TracePublication {
            $data = Validator::make($data, ['key' => 'required|uuid', 'physical_lot_id' => 'nullable|integer|exists:physical_lots,id', 'accepted' => 'accepted'])->validate();
            $data['lot_id'] = $lot->id;

            return $this->commerce->once($actor, 'publish-trace', $data, function () use ($actor, $lot, $data): TracePublication {
                $this->commerce->lockLot($lot);
                $lot = $lot->fresh();
                $physical = isset($data['physical_lot_id']) ? PhysicalLot::query()->findOrFail($data['physical_lot_id']) : null;
                Commerce::ensure($physical !== null || $lot->published_at !== null, 'lot', 'Publica primero la composición comercial revisada.');
                $snapshot = $this->preview($actor, $lot, $physical);
                Commerce::ensure(bccomp($snapshot['quantity'], '0', 3) > 0, 'lot', 'No hay composición para publicar.');
                $token = Str::random(32);
                Commerce::ensure(strlen($this->publicUrl($token)) <= 106, 'url', 'Configura APP_URL con un dominio o una ruta más cortos: el enlace QR admite hasta 106 bytes.');
                app(QrCode::class)->matrix($this->publicUrl($token));
                $publication = TracePublication::query()->create(['lot_id' => $lot->id, 'physical_lot_id' => $physical?->id, 'published_by' => $actor->id, 'token' => $token, 'token_lookup' => hash('sha256', $token), 'public_snapshot' => $snapshot, 'published_at' => Commerce::clock()]);
                AuditLog::record($actor, 'trace.published', $publication, ['lot_id' => $lot->id, 'physical_lot_id' => $physical?->id, 'kind' => $snapshot['kind']]);

                return $publication;
            });
        });
    }

    public function revoke(User $actor, TracePublication $publication, array $data): TracePublication
    {
        return $this->commerce->transaction($actor, 'manage-traceability', function (User $actor) use ($publication, $data): TracePublication {
            $data = Validator::make($data, ['key' => 'required|uuid', 'reason' => 'required|string|min:10|max:2000'])->validate();
            $data['publication_id'] = $publication->id;

            return $this->commerce->once($actor, 'revoke-trace', $data, function () use ($actor, $publication, $data): TracePublication {
                $publication = TracePublication::query()->lockForUpdate()->findOrFail($publication->id);
                Commerce::ensure($publication->revoked_at === null, 'publication', 'La publicación ya está revocada.');
                $publication->update(['revoked_at' => Commerce::clock(), 'revoked_by' => $actor->id, 'revocation_reason' => $data['reason']]);
                AuditLog::record($actor, 'trace.revoked', $publication);

                return $publication;
            });
        });
    }

    public function resolve(string $token): TracePublication
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/D', $token), 404);

        return TracePublication::query()->where('token_lookup', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();
    }

    public function chain(User $actor, Order $order): array
    {
        $this->commerce->authorizeOrder($actor, $order);
        $operator = $actor->hasPermission('manage-commerce');

        return $this->checkout->allocations($order)->with(['portion.offer.producer'])->get()->map(function ($allocation) use ($operator): array {
            $receipts = CollectionReceipt::query()->where('order_allocation_id', $allocation->id)->with(['event', 'photos'])->get();
            $physical = PhysicalLotItem::query()->whereIn('collection_receipt_id', $receipts->pluck('id'))->with('physicalLot')->get();
            $shipments = ShipmentItem::query()->whereIn('physical_lot_item_id', $physical->pluck('id'))->with('shipment')->get();
            $base = [
                'allocation_id' => $allocation->id, 'quantity' => $allocation->quantity, 'released_quantity' => $allocation->released_quantity,
                'receipts' => $receipts->map(fn ($r): array => ['id' => $r->id, 'accepted_quantity' => $r->accepted_quantity, 'rejected_quantity' => $r->rejected_quantity, 'arrived_at' => $r->arrived_at->toIso8601String()])->all(),
                'physical_lots' => $physical->map(fn ($i): array => ['code' => $i->physicalLot->code, 'receipt_id' => $i->collection_receipt_id, 'quantity' => $i->quantity])->all(),
                'shipments' => $shipments->map(fn ($i): array => ['code' => $i->shipment->code, 'physical_item_id' => $i->physical_lot_item_id, 'quantity' => $i->quantity, 'delivered_quantity' => $i->delivered_quantity, 'returned_quantity' => $i->returned_quantity, 'lost_quantity' => $i->lost_quantity])->all(),
            ];

            return $operator ? [...$base, 'producer' => $allocation->portion->offer->producer->full_name, 'offer_id' => $allocation->portion->offer_id, 'agreement_id' => $allocation->agreement_id, 'offer_version' => $allocation->portion->offer_version, 'terms' => $allocation->terms_snapshot] : $base;
        })->all();
    }
}
