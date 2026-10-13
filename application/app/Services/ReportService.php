<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\CollectionPoint;
use App\Models\CollectionReceipt;
use App\Models\Lot;
use App\Models\MarketPrice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Producer;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const TYPES = ['sales' => 'Ventas', 'producers' => 'Productores', 'offers' => 'Ofertas', 'lots' => 'Lotes', 'payments' => 'Pagos', 'settlements' => 'Liquidaciones', 'logistics' => 'Logística', 'prices' => 'Precios', 'audit' => 'Auditoría'];

    public const LIMIT = 2000;

    public function operator(User $actor): bool
    {
        return $actor->hasPermission('view-operations');
    }

    public function authorize(User $actor, string $report = 'sales'): void
    {
        abort_unless($actor->active && array_key_exists($report, self::TYPES), 403);
        if ($report === 'audit') {
            abort_unless($actor->hasPermission('manage-users'), 403);
        } elseif (in_array($report, ['producers', 'offers', 'lots', 'settlements', 'prices'], true)) {
            abort_unless($this->operator($actor), 403);
        } else {
            abort_unless($this->operator($actor) || $actor->hasPermission('view-buyer-area'), 403);
        }
    }

    public function types(User $actor): array
    {
        $types = $this->operator($actor) ? self::TYPES : array_intersect_key(self::TYPES, array_flip(['sales', 'payments', 'logistics']));
        if (! $actor->hasPermission('manage-users')) {
            unset($types['audit']);
        }

        return $types;
    }

    /** Every amount is the current balance of orders confirmed in the selected local-date cohort. */
    public function snapshot(User $actor, array $filters, string $report = 'sales'): array
    {
        $this->authorize($actor, $report);
        $outer = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($actor, $filters, $report, $outer): array {
            if ($outer) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $orders = $this->bounded($this->orders($actor, $filters)->with(['quote', 'items.allocations.portion.offer', 'items.baseUnit', 'items.lot']));
            $ids = $orders->modelKeys();
            $payments = Payment::query()->whereIn('order_id', $ids)->with('method')->get();
            $refunds = PaymentRefund::query()->whereIn('payment_id', $payments->modelKeys())->get();
            $receipts = CollectionReceipt::query()->whereHas('allocation.orderItem', fn ($q) => $q->whereIn('order_id', $ids))->with('allocation.orderItem')->get();
            $shipments = Shipment::query()->whereIn('order_id', $ids)->with('items')->get();
            $settlements = ProducerSettlement::query()->whereIn('order_id', $ids)->with('items')->orderByDesc('version')->orderByDesc('id')->get()->unique(fn ($s) => $s->order_id.':'.$s->producer_id);
            $settledReceiptIds = $settlements->flatMap(fn ($s) => $s->items->pluck('collection_receipt_id'))->flip();
            $payouts = ProducerPayout::query()->whereIn('order_id', $ids)->get();
            $paymentsByOrder = $payments->groupBy('order_id');
            $refundsByPayment = $refunds->groupBy('payment_id');
            $receiptsByOrder = $receipts->groupBy(fn ($receipt) => $receipt->allocation->orderItem->order_id);
            $shipmentsByOrder = $shipments->groupBy('order_id');
            $settlementsByOrder = $settlements->groupBy('order_id');
            $payoutsByOrder = $payouts->groupBy('order_id');
            $rows = [];
            $producerOrders = [];
            foreach ($orders as $order) {
                $quantity = '0.000';
                $gross = 0;
                $priceBasis = '0.000000000';
                foreach ($order->items as $item) {
                    foreach ($item->allocations as $allocation) {
                        $current = bcsub($allocation->quantity, $allocation->released_quantity, 3);
                        $quantity = bcadd($quantity, $current, 3);
                        $gross += Money::line($current, $allocation->gross_unit_price);
                        $priceBasis = bcadd($priceBasis, bcmul($current, $allocation->gross_unit_price, 9), 9);
                        if (bccomp($current, '0', 3) > 0) {
                            $producerOrders[$allocation->portion->offer->producer_id][$order->id] = true;
                        }
                    }
                }
                $ratio = bcdiv($quantity, $order->quote->quantity, 12);
                $fee = $this->prorate($order->quote->fee_minor, $ratio);
                $discount = $this->prorate($order->quote->discount_minor, $ratio);
                $logistics = $this->prorate($order->quote->logistics_minor, $ratio);
                $incoming = $paymentsByOrder->get($order->id, collect())->whereIn('status', ['confirmed', 'partially_refunded', 'refunded']);
                $ownRefunds = $incoming->flatMap(fn ($payment) => $refundsByPayment->get($payment->id, collect()));
                $received = (int) $incoming->sum('amount_minor');
                $returned = (int) $ownRefunds->where('status', 'confirmed')->sum('amount_minor');
                $committedRefund = (int) $ownRefunds->whereIn('status', ['authorized', 'uncertain'])->sum('amount_minor');
                $ownReceipts = $receiptsByOrder->get($order->id, collect());
                $ownShipments = $shipmentsByOrder->get($order->id, collect());
                $shipmentItems = $ownShipments->flatMap(fn ($s) => $s->items);
                $ownSettlements = $settlementsByOrder->get($order->id, collect());
                $net = (int) $ownSettlements->sum('net_minor');
                $paid = (int) $payoutsByOrder->get($order->id, collect())->where('status', 'confirmed')->sum('amount_minor');
                $item = $order->items->first();
                $rows[] = [
                    'id' => $order->id, 'code' => $order->code, 'buyer_id' => $order->buyer_id,
                    'date' => $order->confirmed_at->setTimezone('America/Lima')->format('Y-m-d H:i'),
                    'month' => $order->confirmed_at->setTimezone('America/Lima')->format('Y-m'),
                    'status' => config('commerce.status_labels.'.$order->status, $order->status),
                    'state' => $order->status, 'product' => $item->product_snapshot['product'],
                    'location' => $item->product_snapshot['location'], 'unit' => $item->baseUnit->code,
                    'quantity' => $quantity, 'gmv_minor' => $gross, 'price_basis' => $priceBasis, 'fee_minor' => $fee,
                    'income_minor' => $fee - $discount, 'logistics_minor' => $logistics,
                    'tax_minor' => $order->total_minor - $gross - $fee - $logistics + $discount,
                    'total_minor' => $order->total_minor, 'received_minor' => $received,
                    'refunded_minor' => $returned, 'refund_pending_minor' => $committedRefund,
                    'balance_minor' => $order->total_minor - $received + $returned,
                    'accepted' => $this->sum($ownReceipts, 'accepted_quantity'),
                    'rejected' => $this->sum($ownReceipts, 'rejected_quantity'),
                    'received' => $this->sum($ownReceipts, 'received_quantity'),
                    'delivered' => $this->sum($shipmentItems, 'delivered_quantity'),
                    'producer_net_minor' => $net, 'producer_paid_minor' => $paid,
                    'producer_balance_minor' => $net - $paid,
                ];
            }
            $sales = collect($rows);
            $kg = $sales->where('unit', 'kg');
            $buyerGroups = $sales->where('quantity', '>', 0)->groupBy('buyer_id');
            $metrics = [
                'orders' => count($rows), 'sales' => $sales->where('quantity', '>', 0)->count(),
                'quantity_kg' => $this->sum($kg, 'quantity'), 'gmv_minor' => (int) $sales->sum('gmv_minor'),
                'total_minor' => (int) $sales->sum('total_minor'), 'income_minor' => (int) $sales->sum('income_minor'),
                'fee_minor' => (int) $sales->sum('fee_minor'), 'collected_minor' => (int) ($sales->sum('received_minor') - $sales->sum('refunded_minor')),
                'buyer_due_minor' => (int) $sales->sum(fn ($r) => max(0, $r['balance_minor'])),
                'buyer_credit_minor' => (int) $sales->sum(fn ($r) => max(0, -$r['balance_minor'])),
                'refund_pending_minor' => (int) $sales->sum('refund_pending_minor'),
                'producer_net_minor' => (int) $sales->sum('producer_net_minor'),
                'producer_paid_minor' => (int) $sales->sum('producer_paid_minor'),
                'producer_due_minor' => (int) $sales->sum('producer_balance_minor'),
                'settlements_draft' => $settlements->where('status', 'draft')->count(),
                'receipts_unsettled' => $receipts->filter(fn ($r) => bccomp($r->accepted_quantity, '0', 3) > 0 && ! $settledReceiptIds->has($r->id))->count(),
                'delivery_pending' => $sales->filter(fn ($r) => bccomp($r['quantity'], $r['delivered'], 3) > 0)->count(),
                'payments_pending' => $payments->whereIn('status', ['pending', 'proof_uploaded', 'under_review'])->count(),
                'rejected_kg' => $this->sum($kg, 'rejected'), 'received_kg' => $this->sum($kg, 'received'),
                'repeat_buyers' => $buyerGroups->filter(fn ($group) => $group->count() > 1)->count(),
                'active_buyers' => $buyerGroups->count(), 'active_producers' => count($producerOrders),
                'repeat_producers' => count(array_filter($producerOrders, fn ($ids) => count($ids) > 1)),
                'average_kg' => bccomp($this->sum($kg, 'quantity'), '0', 3) > 0 ? bcdiv($this->sum($kg, 'price_basis', 9), $this->sum($kg, 'quantity'), 6) : null,
            ];
            $operator = $this->operator($actor);
            $inventory = $operator ? $this->inventory($filters) : [];
            $table = $this->table($report, $sales, $filters, $payments, $refunds, $settlements, $payouts, $shipments);
            $charts = $this->charts($sales);
            if ($operator) {
                $offerSeries = [];
                $offers = $this->bounded($this->period($this->dimensions(Offer::query(), $filters), 'created_at', $filters)->whereHas('baseUnit', fn ($q) => $q->where('code', 'kg')));
                foreach ($offers->groupBy(fn ($offer) => $offer->created_at->setTimezone('America/Lima')->format('Y-m'))->sortKeys() as $month => $group) {
                    $offerSeries[] = ['label' => $month.' · ofrecidos', 'value' => $this->sum($group, 'normalized_quantity')];
                }
                foreach ($kg->groupBy('month')->sortKeys() as $month => $group) {
                    $offerSeries[] = ['label' => $month.' · comprados', 'value' => $this->sum($group, 'quantity')];
                }
                $charts[] = ['title' => 'Ofertas registradas y compras confirmadas', 'unit' => 'kg · cohortes independientes; ofertas sin clasificación sintética/real', 'rows' => $offerSeries];
                $participants = [];
                foreach ($orders->groupBy(fn ($order) => $order->confirmed_at->setTimezone('America/Lima')->format('Y-m'))->sortKeys() as $month => $group) {
                    $producerIds = [];
                    foreach ($group as $order) {
                        foreach ($order->items as $item) {
                            foreach ($item->allocations as $allocation) {
                                if (bccomp($allocation->quantity, $allocation->released_quantity, 3) > 0) {
                                    $producerIds[$allocation->portion->offer->producer_id] = true;
                                }
                            }
                        }
                    }
                    $participants[] = ['label' => $month, 'value' => (string) count($producerIds)];
                }
                $charts[] = ['title' => 'Productores activos en compras', 'unit' => 'Productores distintos por mes', 'rows' => $participants];
            }

            return ['filters' => $filters, 'generated_at' => Commerce::clock()->setTimezone('America/Lima')->format('Y-m-d H:i:s'), 'operator' => $operator, 'metrics' => $metrics, 'inventory' => $inventory, 'sales' => $sales, 'charts' => $charts, 'map' => $operator ? $this->map($filters) : [], 'table' => $table, 'report' => $report];
        });
    }

    public function orders(User $actor, array $filters): Builder
    {
        $query = Order::query()->where('synthetic', $filters['data'] === 'synthetic');
        $this->period($query, 'confirmed_at', $filters);
        if (! $this->operator($actor)) {
            $query->whereHas('buyer', fn ($q) => $q->where('user_id', $actor->id));
        }
        if ($filters['product_id'] || $filters['location_id']) {
            $query->whereHas('items.lot', fn ($q) => $this->dimensions($q, $filters));
        }

        return $query->orderBy('id');
    }

    private function period(Builder $query, string $column, array $filters): Builder
    {
        return $query->where($column, '>=', CarbonImmutable::parse($filters['from'], 'America/Lima')->startOfDay()->utc())
            ->where($column, '<', CarbonImmutable::parse($filters['to'], 'America/Lima')->addDay()->startOfDay()->utc());
    }

    private function dimensions(Builder $query, array $filters): Builder
    {
        foreach (['product_id', 'location_id'] as $field) {
            if ($filters[$field]) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query;
    }

    private function bounded(Builder $query): Collection
    {
        $rows = $query->limit(self::LIMIT + 1)->get();
        Commerce::ensure($rows->count() <= self::LIMIT, 'from', 'El informe supera 2,000 filas. Reduce el período o aplica filtros.');

        return $rows;
    }

    private function prorate(int $amount, string $ratio): int
    {
        return (int) bcadd(bcmul((string) $amount, $ratio, 12), '0.5', 0);
    }

    private function sum(iterable $rows, string $field, int $scale = 3): string
    {
        $total = '0.000';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) data_get($row, $field, '0'), $scale);
        }

        return $total;
    }

    private function inventory(array $filters): array
    {
        $lots = app(LotAvailability::class)->marketplace(array_filter(['product_id' => $filters['product_id']]));
        if ($filters['location_id']) {
            $lots->where('lots.location_id', $filters['location_id']);
        }
        $available = $lots->whereHas('baseUnit', fn ($q) => $q->where('code', 'kg'))->get();
        $offers = $this->dimensions(Offer::query(), $filters);

        return ['producers' => Producer::query()->count(), 'verified' => Producer::query()->where('status', 'verified')->count(), 'buyers' => Buyer::query()->count(),
            'offers_active' => $this->dimensions(app(LotAvailability::class)->eligibleOffers(), $filters)->count(),
            'offers_pending' => (clone $offers)->whereIn('status', ['pending_verification', 'needs_information'])->count(),
            'available_kg' => $this->sum($available, 'available_quantity')];
    }

    private function charts(Collection $rows): array
    {
        $charts = [];
        foreach (['month' => 'Ventas por mes', 'product' => 'Volumen por producto y unidad', 'location' => 'Ventas por localidad'] as $key => $title) {
            $series = [];
            $groups = $rows->groupBy(fn ($r) => $key === 'product' ? $r['product'].' · '.$r['unit'] : $r[$key])->sortKeys();
            foreach ($groups as $label => $group) {
                $value = $key === 'product' ? $this->sum($group, 'quantity') : Money::format((int) $group->sum('gmv_minor'));
                $series[] = ['label' => $label, 'value' => $value];
            }
            $charts[] = ['title' => $title, 'unit' => $key === 'product' ? 'Unidad de cada producto' : 'S/ producto', 'rows' => $series];
        }
        $monthly = [];
        $recurrence = [];
        $rejections = [];
        foreach ($rows->groupBy('month')->sortKeys() as $month => $group) {
            $kg = $group->where('unit', 'kg');
            $quantity = $this->sum($kg, 'quantity');
            $monthly[] = ['label' => $month, 'value' => bccomp($quantity, '0', 3) > 0 ? bcdiv($this->sum($kg, 'price_basis', 9), $quantity, 6) : '0'];
            $recurrence[] = ['label' => $month, 'value' => (string) $group->where('quantity', '>', 0)->groupBy('buyer_id')->filter(fn ($g) => $g->count() > 1)->count()];
            $rejections[] = ['label' => $month, 'value' => $this->sum($kg, 'rejected')];
        }
        $charts[] = ['title' => 'Precio promedio ponderado', 'unit' => 'S/ por kg', 'rows' => $monthly];
        $charts[] = ['title' => 'Compradores con más de una compra en el mes', 'unit' => 'Compradores', 'rows' => $recurrence];
        $charts[] = ['title' => 'Rechazos de calidad', 'unit' => 'kg recibidos y rechazados', 'rows' => $rejections];

        return $charts;
    }

    /** Coordinates stay in the operator page; no external tiles, geocoder, addresses or exported coordinates. */
    private function map(array $filters): array
    {
        $points = [];
        $producers = Producer::query()->with(['currentLocation', 'consents' => fn ($q) => $q->where('purpose', 'location_planning')->orderByDesc('recorded_at')->orderByDesc('id')])
            ->whereHas('currentLocation', fn ($q) => $q->whereNotNull('latitude')->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id)))
            ->when($filters['product_id'], fn ($q, $id) => $q->whereIn('id', Offer::query()->where('product_id', $id)->select('producer_id')))->limit(201)->get();
        foreach ($producers->take(200) as $producer) {
            if ($producer->consents->first()?->decision !== 'granted' || $producer->consents->first()?->recorded_at->isFuture()) {
                continue;
            }
            $location = $producer->currentLocation;
            $points[] = ['label' => 'Productor #'.$producer->id, 'kind' => 'Productor', 'latitude' => $location->latitude, 'longitude' => $location->longitude];
            $offers = $this->dimensions(Offer::query()->where('producer_id', $producer->id)->where('status', 'published'), $filters)->limit(20)->get();
            foreach ($offers as $offer) {
                $points[] = ['label' => 'Oferta #'.$offer->id.' · ubicación del productor', 'kind' => 'Oferta', 'latitude' => $location->latitude, 'longitude' => $location->longitude];
            }
            $lotIds = DB::table('lot_offers')->whereIn('offer_id', $offers->modelKeys())->distinct()->pluck('lot_id');
            foreach (Lot::query()->whereIn('id', $lotIds)->where('status', 'published')->limit(20)->get() as $lot) {
                $points[] = ['label' => $lot->code.' · origen, no acopio', 'kind' => 'Lote', 'latitude' => $location->latitude, 'longitude' => $location->longitude];
            }
        }
        foreach (CollectionPoint::query()->where('active', true)->whereNotNull('latitude')->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))->limit(200)->get() as $point) {
            $points[] = ['label' => $point->name, 'kind' => 'Acopio', 'latitude' => $point->latitude, 'longitude' => $point->longitude];
        }

        return $points;
    }

    private function table(string $report, Collection $sales, array $filters, Collection $payments, Collection $refunds, Collection $settlements, Collection $payouts, Collection $shipments): array
    {
        $rows = [];
        $headers = [];
        $basis = 'Órdenes confirmadas en el período; saldos actuales al generar. Moneda PEN.';
        switch ($report) {
            case 'sales':
                $headers = ['Orden', 'Confirmada (Perú)', 'Estado', 'Producto', 'Localidad', 'Unidad', 'Cantidad vigente', 'Producto PEN', 'Total PEN', 'Cobrado neto PEN', 'Saldo comprador PEN'];
                foreach ($sales as $sale) {
                    $rows[] = [$sale['code'], $sale['date'], $sale['status'], $sale['product'], $sale['location'], $sale['unit'], $sale['quantity'], Money::format($sale['gmv_minor']), Money::format($sale['total_minor']), Money::format($sale['received_minor'] - $sale['refunded_minor']), Money::format($sale['balance_minor'])];
                }
                break;
            case 'payments':
                $headers = ['Cobro', 'Orden', 'Medio', 'Estado', 'Declarado PEN', 'Confirmado PEN', 'Devuelto PEN', 'Confirmado (Perú)'];
                foreach ($payments as $payment) {
                    $rows[] = [(string) $payment->id, $sales->firstWhere('id', $payment->order_id)['code'], $payment->method->name, $this->label($payment->status), Money::format($payment->amount_minor), Money::format(in_array($payment->status, ['confirmed', 'partially_refunded', 'refunded']) ? $payment->amount_minor : 0), Money::format((int) $refunds->where('payment_id', $payment->id)->where('status', 'confirmed')->sum('amount_minor')), $payment->confirmed_at?->setTimezone('America/Lima')->format('Y-m-d H:i') ?? 'Pendiente'];
                }
                break;
            case 'settlements':
                $headers = ['Liquidación', 'Orden', 'Productor interno', 'Versión vigente', 'Estado', 'Aceptado', 'Bruto PEN', 'Costos pactados PEN', 'Neto PEN', 'Pagado PEN', 'Pendiente PEN'];
                foreach ($settlements as $s) {
                    $paid = (int) $payouts->where('order_id', $s->order_id)->where('producer_id', $s->producer_id)->where('status', 'confirmed')->sum('amount_minor');
                    $rows[] = [(string) $s->id, $sales->firstWhere('id', $s->order_id)['code'], (string) $s->producer_id, (string) $s->version, $this->label($s->status), $s->quantity, Money::format($s->gross_minor), Money::format($s->agreed_cost_minor), Money::format($s->net_minor), Money::format($paid), Money::format($s->net_minor - $paid)];
                }
                break;
            case 'logistics':
                $headers = ['Despacho', 'Orden', 'Estado', 'Programado (Perú)', 'Unidad', 'Cargado', 'Entregado', 'Devuelto', 'Perdido', 'Costo PEN'];
                foreach ($shipments as $s) {
                    $sale = $sales->firstWhere('id', $s->order_id);
                    $rows[] = [$s->code, $sale['code'], $this->label($s->status), $s->scheduled_at->setTimezone('America/Lima')->format('Y-m-d H:i'), $sale['unit'], $this->sum($s->items, 'quantity'), $this->sum($s->items, 'delivered_quantity'), $this->sum($s->items, 'returned_quantity'), $this->sum($s->items, 'lost_quantity'), Money::format($s->cost_minor)];
                }
                break;
            case 'producers':
                $basis = 'Productores registrados en el período. Sin DNI, teléfono, dirección ni coordenadas. No se clasifica este padrón por operación sintética/real.';
                $headers = ['Código interno', 'Nombre', 'Estado', 'Tipo', 'Localidad registrada', 'Alta (Perú)'];
                $query = $this->period(Producer::query()->with('currentLocation.location'), 'created_at', $filters);
                if ($filters['location_id']) {
                    $query->whereHas('currentLocation', fn ($q) => $q->where('location_id', $filters['location_id']));
                }
                if ($filters['product_id']) {
                    $query->whereIn('id', Offer::query()->where('product_id', $filters['product_id'])->select('producer_id'));
                }
                foreach ($this->bounded($query->orderBy('id')) as $p) {
                    $rows[] = [(string) $p->id, $p->full_name, Producer::STATUSES[$p->status] ?? $p->status, Producer::TYPES[$p->type] ?? $p->type, $p->currentLocation?->location?->name ?? 'Sin localidad', $p->created_at->setTimezone('America/Lima')->format('Y-m-d H:i')];
                }
                break;
            case 'offers':
            case 'lots':
                $basis = 'Registros creados en el período, con su estado actual. Este catálogo no se clasifica por operación sintética/real.';
                $headers = ['Código', 'Producto', 'Localidad', 'Estado', 'Unidad', 'Cantidad original', 'Vigencia hasta'];
                $query = $report === 'offers' ? Offer::query() : Lot::query();
                $query = $this->period($this->dimensions($query, $filters), 'created_at', $filters)->with(['product', 'location', 'baseUnit'])->orderBy('id');
                foreach ($this->bounded($query) as $row) {
                    $quantity = $report === 'offers' ? $row->normalized_quantity : $this->sum($row->portions, 'quantity');
                    $rows[] = $report === 'offers' ? ['Oferta #'.$row->id, $row->product->name, $row->location->name, Offer::STATUSES[$row->status] ?? $row->status, $row->baseUnit->code, $quantity, $row->available_until->toDateString()] : [$row->code, $row->product->name, $row->location->name, Lot::STATUSES[$row->status] ?? $row->status, $row->baseUnit->code, $quantity, $row->available_until->toDateString()];
                }
                break;
            case 'prices':
                $basis = 'Referencias observadas en el período; importes orientativos, no equivalen al neto productor.';
                $headers = ['Referencia', 'Producto', 'Localidad', 'Unidad', 'Precio PEN', 'Fecha observada', 'Mercado', 'Fuente'];
                $query = $this->dimensions(MarketPrice::query(), $filters)->where('synthetic', $filters['data'] === 'synthetic')->whereBetween('observed_on', [$filters['from'], $filters['to']])->with(['product', 'location', 'unit'])->orderBy('id');
                foreach ($this->bounded($query) as $p) {
                    $rows[] = [(string) $p->id, $p->product->name, $p->location->name, $p->unit->code, $p->unit_price, $p->observed_on->toDateString(), $p->market, $p->source];
                }
                break;
            case 'audit':
                $basis = 'Eventos registrados en el período. Sin valores privados, payloads, secretos ni IP. Filtros de producto/localidad y tipo comercial no aplican.';
                Commerce::ensure(! $filters['product_id'] && ! $filters['location_id'], 'report', 'Auditoría admite solamente el período de fechas.');
                $headers = ['Evento', 'Fecha (Perú)', 'Actor interno', 'Acción', 'Tipo de registro', 'Registro interno'];
                foreach ($this->bounded($this->period(AuditLog::query(), 'occurred_at', $filters)->orderBy('id')) as $event) {
                    $rows[] = [(string) $event->id, $event->occurred_at->setTimezone('America/Lima')->format('Y-m-d H:i:s'), (string) $event->actor_id, $event->action, class_basename($event->subject_type), (string) $event->subject_id];
                }
                break;
        }
        Commerce::ensure(count($rows) <= self::LIMIT, 'from', 'Reduce el período: el informe supera 2,000 filas.');

        return ['title' => self::TYPES[$report], 'basis' => $basis, 'headers' => $headers, 'rows' => $rows];
    }

    private function label(string $value): string
    {
        return config('commerce.status_labels.'.$value, $value);
    }
}
