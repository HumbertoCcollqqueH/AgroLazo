<?php

namespace App\Services;

use App\Models\CollectionReceipt;
use App\Models\LogisticsIncident;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class SettlementService
{
    public function __construct(private Commerce $commerce, private CheckoutService $checkout, private PaymentService $payments) {}

    /** Aggregate each allocation before rounding, then distribute its cents among receipt lines. */
    public function snapshot(Order $order, int $producerId): array
    {
        $rows = [];
        foreach ($this->checkout->allocations($order)->whereHas('portion.offer', fn ($q) => $q->where('producer_id', $producerId))->orderBy('id')->get() as $allocation) {
            $receipts = CollectionReceipt::query()->where('order_allocation_id', $allocation->id)->where('accepted_quantity', '>', 0)->orderBy('id')->get();
            if ($receipts->isEmpty()) {
                continue;
            }
            $weights = $receipts->pluck('accepted_quantity', 'id')->all();
            $quantity = array_reduce($weights, fn (string $sum, string $qty): string => bcadd($sum, $qty, 3), '0');
            $gross = Money::line($quantity, $allocation->gross_unit_price);
            $cost = Money::line($quantity, $allocation->producer_unit_cost);
            Commerce::ensure($cost <= $gross, 'settlement', 'Los costos pactados exceden el bruto aceptado.');
            $grossLines = Money::distribute($gross, $weights);
            $costLines = $gross > 0 ? Money::distribute($cost, array_map('strval', $grossLines)) : array_fill_keys(array_keys($weights), 0);
            foreach ($receipts as $receipt) {
                $rows[] = ['order_allocation_id' => $allocation->id, 'collection_receipt_id' => $receipt->id, 'agreement_id' => $allocation->agreement_id, 'quantity' => $receipt->accepted_quantity, 'gross_minor' => $grossLines[$receipt->id], 'agreed_cost_minor' => $costLines[$receipt->id], 'net_minor' => $grossLines[$receipt->id] - $costLines[$receipt->id], 'terms_snapshot' => $allocation->terms_snapshot];
            }
        }
        Commerce::ensure($rows !== [], 'producer_id', 'Este productor no tiene peso aceptado en la orden.');
        $quantity = array_reduce($rows, fn (string $sum, array $row): string => bcadd($sum, $row['quantity'], 3), '0');
        $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));

        return ['items' => $rows, 'snapshot_hash' => $hash, 'quantity' => $quantity, 'gross_minor' => array_sum(array_column($rows, 'gross_minor')), 'agreed_cost_minor' => array_sum(array_column($rows, 'agreed_cost_minor')), 'net_minor' => array_sum(array_column($rows, 'net_minor'))];
    }

    public function calculate(User $actor, Order $order, array $data): ProducerSettlement
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($order, $data): ProducerSettlement {
            $data = Validator::make($data, ['key' => 'required|uuid', 'producer_id' => 'required|integer|exists:producers,id'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'calculate-settlement', $data, function () use ($actor, $order, $data): ProducerSettlement {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure($order->status !== 'closed', 'order', 'La orden está cerrada.');
                $snapshot = $this->snapshot($order, (int) $data['producer_id']);
                $latest = ProducerSettlement::query()->where('order_id', $order->id)->where('producer_id', $data['producer_id'])->latest('version')->first();
                if ($latest && hash_equals($latest->snapshot_hash, $snapshot['snapshot_hash'])) {
                    return $latest;
                }
                $settlement = ProducerSettlement::query()->create(['order_id' => $order->id, 'producer_id' => $data['producer_id'], 'version' => ($latest?->version ?? 0) + 1, ...collect($snapshot)->except('items')->all(), 'currency' => 'PEN', 'status' => 'draft', 'calculated_by' => $actor->id]);
                $settlement->items()->createMany($snapshot['items']);
                $this->payments->review($actor, $order, null, 'settlement_calculated', 'Liquidación calculada desde peso aceptado y condiciones congeladas.', ['settlement_id' => $settlement->id]);
                $this->commerce->event($actor, 'settlement_calculated', $order, $order->quote->lot, ['settlement_id' => $settlement->id]);

                return $settlement;
            });
        });
    }

    public function approve(User $actor, ProducerSettlement $settlement, array $data): ProducerSettlement
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($settlement, $data): ProducerSettlement {
            $data = Validator::make($data, ['key' => 'required|uuid', 'notes' => 'required|string|min:20|max:3000'])->validate();
            $data['settlement_id'] = $settlement->id;

            return $this->commerce->once($actor, 'approve-settlement', $data, function () use ($actor, $settlement, $data): ProducerSettlement {
                $order = $this->checkout->lockOrder($settlement->order);
                $settlement = ProducerSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
                $latest = $this->latest($order, $settlement->producer_id);
                Commerce::ensure($latest?->id === $settlement->id && $settlement->status === 'draft', 'settlement', 'Aprueba la última versión pendiente.');
                Commerce::ensure(hash_equals($settlement->snapshot_hash, $this->snapshot($order, $settlement->producer_id)['snapshot_hash']), 'settlement', 'Cambió el peso aceptado. Calcula una nueva revisión.');
                $held = $this->payouts($order, $settlement->producer_id)->whereIn('status', PaymentService::HELD)->sum('amount_minor');
                Commerce::ensure($settlement->net_minor >= (int) $held, 'settlement', 'La nueva obligación es menor que los desembolsos comprometidos. Requiere conciliación; no se crea un pago negativo.');
                $settlement->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => Commerce::clock()]);
                $this->payments->review($actor, $order, null, 'settlement_approved', $data['notes'], ['settlement_id' => $settlement->id]);
                $this->commerce->event($actor, 'settlement_approved', $order, $order->quote->lot, ['settlement_id' => $settlement->id]);

                return $settlement;
            });
        });
    }

    public function authorize(User $actor, ProducerSettlement $settlement, array $data): ProducerPayout
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($settlement, $data): ProducerPayout {
            $data = Validator::make($data, ['key' => 'required|uuid', 'amount' => 'required|string', 'reason' => 'required|string|min:20|max:3000'])->validate();
            $data['amount_minor'] = Money::amount($data['amount']);
            unset($data['amount']);
            $data['settlement_id'] = $settlement->id;

            return $this->commerce->once($actor, 'authorize-payout', $data, function () use ($actor, $settlement, $data): ProducerPayout {
                $order = $this->checkout->lockOrder($settlement->order);
                Commerce::ensure($order->status !== 'closed', 'order', 'La orden está cerrada.');
                $latest = $this->latest($order, $settlement->producer_id);
                Commerce::ensure($latest?->id === $settlement->id && $latest->status === 'approved', 'settlement', 'Utiliza la última liquidación aprobada.');
                Commerce::ensure(hash_equals($latest->snapshot_hash, $this->snapshot($order, $settlement->producer_id)['snapshot_hash']), 'settlement', 'Hay nuevas recepciones. Calcula y aprueba la liquidación vigente.');
                $held = (int) $this->payouts($order, $settlement->producer_id)->whereIn('status', PaymentService::HELD)->sum('amount_minor');
                Commerce::ensure($data['amount_minor'] <= $latest->net_minor - $held, 'amount', 'El importe supera el saldo disponible después de todos los desembolsos y autorizaciones anteriores.');
                $payout = ProducerPayout::query()->create(['producer_settlement_id' => $settlement->id, 'order_id' => $order->id, 'producer_id' => $settlement->producer_id, 'authorized_by' => $actor->id, 'amount_minor' => $data['amount_minor'], 'status' => 'authorized', 'version' => 1, 'reason' => $data['reason'], 'recipient_snapshot' => app(ProducerPayoutProfiles::class)->snapshot($actor, $settlement->producer)]);
                $this->payments->review($actor, $order, null, 'payout_authorized', $data['reason'], ['payout_id' => $payout->id]);

                return $payout;
            });
        });
    }

    public function outcome(User $actor, ProducerPayout $payout, array $data): ProducerPayout
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($payout, $data): ProducerPayout {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'result' => 'required|in:confirmed,uncertain,failed', 'operation_reference' => 'required|string|min:5|max:120', 'notes' => 'required|string|min:20|max:3000'])->validate();
            $data['payout_id'] = $payout->id;

            return $this->commerce->once($actor, 'payout-outcome', $data, function () use ($actor, $payout, $data): ProducerPayout {
                $order = $this->checkout->lockOrder($payout->order);
                $payout = ProducerPayout::query()->lockForUpdate()->findOrFail($payout->id);
                Commerce::ensure($payout->version === (int) $data['version'] && in_array($payout->status, ['authorized', 'uncertain'], true), 'version', 'El desembolso cambió o ya está resuelto.');
                $lookup = $data['result'] === 'confirmed' ? PaymentService::fingerprint('payout', $data['operation_reference']) : null;
                Commerce::ensure($lookup === null || ! ProducerPayout::query()->where('reference_lookup', $lookup)->whereKeyNot($payout->id)->exists(), 'operation_reference', 'La referencia externa ya está registrada.');
                $payout->update(['status' => $data['result'], 'version' => $payout->version + 1, 'operation_reference' => $data['operation_reference'], 'reference_lookup' => $lookup, 'confirmed_by' => $data['result'] === 'confirmed' ? $actor->id : null, 'confirmed_at' => $data['result'] === 'confirmed' ? Commerce::clock() : null]);
                $this->payments->review($actor, $order, null, 'payout_'.$data['result'], $data['notes'], ['payout_id' => $payout->id]);
                $this->commerce->event($actor, 'payout_'.$data['result'], $order, $order->quote->lot, ['payout_id' => $payout->id]);

                return $payout;
            });
        });
    }

    public function latest(Order $order, int $producerId): ?ProducerSettlement
    {
        return ProducerSettlement::query()->where('order_id', $order->id)->where('producer_id', $producerId)->latest('version')->first();
    }

    public function payouts(Order $order, int $producerId): Builder
    {
        return ProducerPayout::query()->where('order_id', $order->id)->where('producer_id', $producerId);
    }

    public function balance(ProducerSettlement $settlement): array
    {
        $latest = $this->latest($settlement->order, $settlement->producer_id);
        $paid = (int) $this->payouts($settlement->order, $settlement->producer_id)->where('status', 'confirmed')->sum('amount_minor');
        $pending = (int) $this->payouts($settlement->order, $settlement->producer_id)->whereIn('status', ['authorized', 'uncertain'])->sum('amount_minor');

        return ['current_version' => $latest->version, 'net_minor' => $latest->net_minor, 'paid_minor' => $paid, 'pending_minor' => $pending, 'remaining_minor' => $latest->net_minor - $paid, 'available_to_authorize_minor' => $latest->net_minor - $paid - $pending];
    }

    public function close(User $actor, Order $order, array $data): Order
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($order, $data): Order {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'notes' => 'required|string|min:20|max:3000'])->validate();
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'close-order', $data, function () use ($actor, $order, $data): Order {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure($order->version === (int) $data['version'] && in_array($order->status, ['delivered', 'cancelled'], true), 'order', 'Completa o cancela la operación con sus ajustes antes del cierre financiero.');
                $balance = $this->payments->balance($order);
                Commerce::ensure($balance['buyer_balance_minor'] === 0 && $balance['refund_pending_minor'] === 0, 'payment', 'El saldo comprador o los reembolsos siguen pendientes.');
                Commerce::ensure(! Payment::query()->where('order_id', $order->id)->whereIn('status', ['pending', 'proof_uploaded', 'under_review'])->exists(), 'payment', 'Resuelve los cobros pendientes de revisión.');
                Commerce::ensure(! LogisticsIncident::query()->where('order_id', $order->id)->where('status', 'open')->exists(), 'incident', 'Hay incidencias de mercancía sin resolver.');
                $producerIds = $this->checkout->allocations($order)->with('portion.offer')->get()->map(fn ($a) => $a->portion->offer->producer_id)->unique();
                foreach ($producerIds as $producerId) {
                    $hasReceipts = CollectionReceipt::query()->whereIn('order_allocation_id', $this->checkout->allocations($order)->whereHas('portion.offer', fn ($q) => $q->where('producer_id', $producerId))->select('id'))->where('accepted_quantity', '>', 0)->exists();
                    if (! $hasReceipts) {
                        continue;
                    }
                    $latest = $this->latest($order, $producerId);
                    Commerce::ensure($latest !== null && $latest->status === 'approved', 'settlement', 'Falta aprobar la liquidación de un productor.');
                    $current = $this->snapshot($order, $producerId);
                    Commerce::ensure(hash_equals($latest->snapshot_hash, $current['snapshot_hash']), 'settlement', 'Una liquidación no refleja todas las recepciones.');
                    $producerBalance = $this->balance($latest);
                    Commerce::ensure($producerBalance['remaining_minor'] === 0 && $producerBalance['pending_minor'] === 0, 'settlement', 'Quedan obligaciones o desembolsos pendientes.');
                }
                $order->update(['status' => 'closed', 'version' => $order->version + 1]);
                $this->payments->review($actor, $order, null, 'order_closed', $data['notes']);
                $this->commerce->event($actor, 'order_closed', $order, $order->quote->lot);

                return $order;
            });
        });
    }
}
