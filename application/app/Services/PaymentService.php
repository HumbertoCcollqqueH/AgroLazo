<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\PaymentRefund;
use App\Models\PaymentReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public const HELD = ['authorized', 'uncertain', 'confirmed'];

    public function __construct(private Commerce $commerce, private CheckoutService $checkout) {}

    public static function fingerprint(string $scope, string $reference): string
    {
        return hash_hmac('sha256', $scope.':'.mb_strtoupper(preg_replace('/\s+/u', '', trim($reference))), config('app.key'));
    }

    public function declare(User $actor, Order $order, array $data): Payment
    {
        return $this->commerce->transaction($actor, null, function (User $actor) use ($order, $data): Payment {
            $this->commerce->authorizeOrder($actor, $order);
            $data = Validator::make($data, ['key' => 'required|uuid', 'method_id' => 'required|integer|exists:payment_methods,id', 'amount' => 'required|string', 'operation_reference' => 'required|string|min:3|max:100', 'occurred_at' => 'required|date_format:Y-m-d\TH:i'])->validate();
            $data['amount_minor'] = Money::amount($data['amount']);
            unset($data['amount']);
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'declare-payment', $data, function () use ($actor, $order, $data): Payment {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure($order->status !== 'closed', 'order', 'La orden está cerrada. Registra y concilia cualquier incidencia antes de nuevos cobros.');
                $method = PaymentMethod::query()->findOrFail($data['method_id']);
                Commerce::ensure($method->active && ($order->synthetic || $method->live_ready), 'method_id', 'Falta configurar y revisar el receptor de este medio.');
                Commerce::ensure($order->synthetic ? app()->environment(['local', 'testing']) : (bool) config('commerce.live_enabled'), 'order', 'Los cobros reales no están habilitados.');
                $occurred = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], 'America/Lima')->startOfMinute();
                Commerce::ensure($occurred->lessThanOrEqualTo(Commerce::clock()) && $occurred->greaterThanOrEqualTo($order->confirmed_at->startOfMinute()), 'occurred_at', 'El pago debe ser posterior a la compra y no futuro.');
                $lookup = self::fingerprint('incoming:'.$method->code.':'.$occurred->toDateString(), $data['operation_reference']);
                Commerce::ensure(! Payment::query()->where('reference_lookup', $lookup)->exists(), 'operation_reference', 'Esa referencia ya está registrada para el medio y la fecha. Revisa el cobro existente.');
                $payment = Payment::query()->create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'recorded_by' => $actor->id, 'amount_minor' => $data['amount_minor'], 'currency' => 'PEN', 'operation_reference' => $data['operation_reference'], 'reference_lookup' => $lookup, 'occurred_at' => $occurred->utc(), 'recipient_snapshot' => app(ManualPaymentGateway::class)->instructions($method, $order->synthetic), 'status' => 'pending', 'version' => 1]);
                $this->review($actor, $order, $payment, 'declared', 'Registro pendiente de verificar; no acredita fondos.', ['amount_minor' => $payment->amount_minor]);
                $this->commerce->event($actor, 'payment_declared', $order, $order->quote->lot, ['payment_id' => $payment->id]);

                return $payment;
            });
        });
    }

    public function proof(User $actor, Payment $payment, array $data, UploadedFile $file): PaymentProof
    {
        Validator::make(['proof' => $file], ['proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120'])->validate();
        $path = null;
        try {
            return $this->commerce->transaction($actor, null, function (User $actor) use ($payment, $data, $file, &$path): PaymentProof {
                $this->commerce->authorizeOrder($actor, $payment->order);
                $order = $this->checkout->lockOrder($payment->order);
                $data = Validator::make($data, ['key' => 'required|uuid'])->validate();
                $data['payment_id'] = $payment->id;
                $data['sha256'] = hash_file('sha256', $file->getRealPath());

                return $this->commerce->once($actor, 'payment-proof', $data, function () use ($actor, $order, $payment, $file, $data, &$path): PaymentProof {
                    $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                    $existing = PaymentProof::query()->where('sha256', $data['sha256'])->first();
                    if ($existing) {
                        Commerce::ensure($existing->payment_id === $payment->id, 'proof', 'El archivo ya se utilizó en otro cobro. Debe revisarse la duplicidad antes de acreditar dinero.');

                        return $existing;
                    }
                    Commerce::ensure(in_array($payment->status, ['pending', 'proof_uploaded'], true), 'payment', 'El cobro ya está en revisión o resuelto; conserva su evidencia.');
                    Commerce::ensure($payment->proofs()->count() < 5, 'proof', 'Se admiten hasta cinco comprobantes por registro.');
                    $path = 'payment-proofs/'.Str::uuid().'.'.$file->extension();
                    Commerce::ensure(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 'proof', 'No se pudo guardar el comprobante privado.');
                    $proof = PaymentProof::query()->create(['payment_id' => $payment->id, 'recorded_by' => $actor->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $data['sha256']]);
                    $payment->update(['status' => 'proof_uploaded', 'version' => $payment->version + 1]);
                    $this->review($actor, $order, $payment, 'proof_uploaded', 'Comprobante recibido; el ingreso sigue sin confirmar.', ['proof_id' => $proof->id]);

                    return $proof;
                });
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function reviewPayment(User $actor, Payment $payment, array $data): Payment
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($payment, $data): Payment {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'result' => 'required|in:under_review,confirmed,rejected', 'notes' => 'required|string|min:20|max:3000'])->validate();
            $data['payment_id'] = $payment->id;

            return $this->commerce->once($actor, 'review-payment', $data, function () use ($actor, $payment, $data): Payment {
                $order = $this->checkout->lockOrder($payment->order);
                $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                Commerce::ensure($payment->version === (int) $data['version'], 'version', 'El registro cambió. Revisa su estado actual.');
                $allowed = ['pending' => ['under_review', 'rejected'], 'proof_uploaded' => ['under_review', 'rejected'], 'under_review' => ['confirmed', 'rejected']];
                Commerce::ensure(in_array($data['result'], $allowed[$payment->status] ?? [], true), 'result', 'Pasa por revisión antes de confirmar. Un cobro resuelto no se reescribe.');
                $before = $payment->status;
                $payment->update(['status' => $data['result'], 'version' => $payment->version + 1, 'reviewed_by' => $actor->id, 'confirmed_at' => $data['result'] === 'confirmed' ? Commerce::clock() : null]);
                $this->review($actor, $order, $payment, $data['result'], $data['notes'], ['before' => $before]);
                $this->commerce->event($actor, 'payment_'.$data['result'], $order, $order->quote->lot, ['payment_id' => $payment->id]);

                return $payment;
            });
        });
    }

    public function refund(User $actor, Payment $payment, array $data): PaymentRefund
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($payment, $data): PaymentRefund {
            $data = Validator::make($data, ['key' => 'required|uuid', 'amount' => 'required|string', 'reason' => 'required|string|min:20|max:3000'])->validate();
            $data['amount_minor'] = Money::amount($data['amount']);
            unset($data['amount']);
            $data['payment_id'] = $payment->id;

            return $this->commerce->once($actor, 'authorize-refund', $data, function () use ($actor, $payment, $data): PaymentRefund {
                $order = $this->checkout->lockOrder($payment->order);
                Commerce::ensure($order->status !== 'closed', 'order', 'La orden está cerrada.');
                $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                Commerce::ensure(in_array($payment->status, ['confirmed', 'partially_refunded'], true), 'payment', 'Solo se puede devolver dinero de un ingreso confirmado.');
                $held = (int) $payment->refunds()->whereIn('status', self::HELD)->sum('amount_minor');
                Commerce::ensure($data['amount_minor'] <= $payment->amount_minor - $held, 'amount', 'El importe supera el saldo reembolsable, incluyendo devoluciones autorizadas o de resultado incierto.');
                $refund = $payment->refunds()->create(['authorized_by' => $actor->id, 'amount_minor' => $data['amount_minor'], 'reason' => $data['reason'], 'status' => 'authorized', 'version' => 1]);
                $this->review($actor, $order, $payment, 'refund_authorized', $data['reason'], ['refund_id' => $refund->id, 'amount_minor' => $refund->amount_minor]);
                $this->commerce->event($actor, 'refund_authorized', $order, $order->quote->lot, ['refund_id' => $refund->id]);

                return $refund;
            });
        });
    }

    public function refundOutcome(User $actor, PaymentRefund $refund, array $data): PaymentRefund
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($refund, $data): PaymentRefund {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'result' => 'required|in:confirmed,uncertain,failed', 'operation_reference' => 'required|string|min:5|max:120', 'notes' => 'required|string|min:20|max:3000'])->validate();
            $data['refund_id'] = $refund->id;

            return $this->commerce->once($actor, 'refund-outcome', $data, function () use ($actor, $refund, $data): PaymentRefund {
                $order = $this->checkout->lockOrder($refund->payment->order);
                $refund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
                Commerce::ensure($refund->version === (int) $data['version'] && in_array($refund->status, ['authorized', 'uncertain'], true), 'version', 'La devolución cambió o ya está resuelta.');
                $lookup = $data['result'] === 'confirmed' ? self::fingerprint('refund', $data['operation_reference']) : null;
                Commerce::ensure($lookup === null || ! PaymentRefund::query()->where('reference_lookup', $lookup)->whereKeyNot($refund->id)->exists(), 'operation_reference', 'La operación externa ya está registrada.');
                $refund->update(['status' => $data['result'], 'version' => $refund->version + 1, 'operation_reference' => $data['operation_reference'], 'reference_lookup' => $lookup, 'confirmed_by' => $data['result'] === 'confirmed' ? $actor->id : null, 'confirmed_at' => $data['result'] === 'confirmed' ? Commerce::clock() : null]);
                $payment = $refund->payment()->lockForUpdate()->firstOrFail();
                $confirmed = (int) $payment->refunds()->where('status', 'confirmed')->sum('amount_minor');
                $payment->update(['status' => $confirmed === $payment->amount_minor ? 'refunded' : ($confirmed > 0 ? 'partially_refunded' : 'confirmed'), 'version' => $payment->version + 1]);
                $this->review($actor, $order, $payment, 'refund_'.$data['result'], $data['notes'], ['refund_id' => $refund->id]);
                $this->commerce->event($actor, 'refund_'.$data['result'], $order, $order->quote->lot, ['refund_id' => $refund->id]);

                return $refund;
            });
        });
    }

    public function balance(Order $order): array
    {
        $received = (int) Payment::query()->where('order_id', $order->id)->whereIn('status', ['confirmed', 'partially_refunded', 'refunded'])->sum('amount_minor');
        $refunds = PaymentRefund::query()->whereHas('payment', fn ($q) => $q->where('order_id', $order->id));
        $refunded = (int) (clone $refunds)->where('status', 'confirmed')->sum('amount_minor');
        $refundPending = (int) (clone $refunds)->whereIn('status', ['authorized', 'uncertain'])->sum('amount_minor');
        $net = $received - $refunded;

        return ['received_minor' => $received, 'refunded_minor' => $refunded, 'refund_pending_minor' => $refundPending, 'net_received_minor' => $net, 'buyer_balance_minor' => $order->total_minor - $net];
    }

    public function assertDispatchAllowed(Order $order): void
    {
        if ($order->synthetic && app()->environment(['local', 'testing'])) {
            return;
        }
        Commerce::ensure((bool) config('commerce.live_enabled'), 'payment', 'Las operaciones reales están desactivadas.');
        $balance = $this->balance($order);
        $covered = $balance['net_received_minor'] - $balance['refund_pending_minor'];
        $deferred = $order->deferred_payment_approved_by !== null && $order->deferred_payment_due_at?->greaterThan(Commerce::clock())
            && $order->deferred_payment_limit_minor >= max(0, $order->total_minor - $covered);
        Commerce::ensure($covered >= $order->total_minor || $deferred, 'payment', 'Falta confirmar el pago o una autorización administrativa de pago diferido vigente.');
    }

    public function defer(User $actor, Order $order, array $data): Order
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($order, $data): Order {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'amount' => 'required|string', 'due_at' => 'required|date_format:Y-m-d\TH:i', 'reason' => 'required|string|min:20|max:3000'])->validate();
            $data['amount_minor'] = Money::amount($data['amount']);
            unset($data['amount']);
            $data['order_id'] = $order->id;

            return $this->commerce->once($actor, 'deferred-payment', $data, function () use ($actor, $order, $data): Order {
                $order = $this->checkout->lockOrder($order);
                Commerce::ensure($order->version === (int) $data['version'] && ! in_array($order->status, ['closed', 'cancelled', 'delivered'], true), 'version', 'La orden cambió o está cerrada.');
                $due = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['due_at'], 'America/Lima')->startOfMinute()->utc();
                Commerce::ensure($due->greaterThan(Commerce::clock()), 'due_at', 'El vencimiento debe ser futuro.');
                Commerce::ensure($data['amount_minor'] <= $order->total_minor, 'amount', 'El límite no debe superar el total vigente.');
                $order->update(['deferred_payment_approved_by' => $actor->id, 'deferred_payment_limit_minor' => $data['amount_minor'], 'deferred_payment_due_at' => $due, 'deferred_payment_reason' => $data['reason'], 'version' => $order->version + 1]);
                $this->review($actor, $order, null, 'deferred_payment_authorized', $data['reason'], ['limit_minor' => $data['amount_minor'], 'due_at' => $due->toIso8601String()]);

                return $order;
            });
        });
    }

    public function configureMethod(User $actor, PaymentMethod $method, array $data): PaymentMethod
    {
        return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($method, $data): PaymentMethod {
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', 'recipient' => 'required|string|min:5|max:1000', 'instructions' => 'required|string|min:20|max:3000', 'active' => 'required|boolean', 'live_ready' => 'required|boolean'])->validate();
            $data['method_id'] = $method->id;

            return $this->commerce->once($actor, 'configure-payment-method', $data, function () use ($actor, $method, $data): PaymentMethod {
                $method = PaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
                Commerce::ensure($method->version === (int) $data['version'], 'version', 'El medio cambió. Recarga la configuración.');
                $method->update([...collect($data)->only(['recipient', 'instructions', 'active', 'live_ready'])->all(), 'version' => $method->version + 1]);
                AuditLog::record($actor, 'payment_method.configured', $method, ['version' => $method->version, 'live_ready' => $method->live_ready]);

                return $method;
            });
        });
    }

    public function review(User $actor, Order $order, ?Payment $payment, string $action, string $notes, array $details = []): void
    {
        PaymentReview::query()->create(['order_id' => $order->id, 'payment_id' => $payment?->id, 'actor_id' => $actor->id, 'action' => $action, 'notes' => $notes, 'details' => $details, 'occurred_at' => Commerce::clock()]);
    }
}
