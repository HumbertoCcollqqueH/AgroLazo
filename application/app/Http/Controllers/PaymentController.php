<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\PaymentRefund;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Services\Commerce;
use App\Services\PaymentService;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function methods(): View
    {
        Gate::authorize('confirm-payments');

        return view('commerce.payment-methods', ['methods' => PaymentMethod::query()->orderBy('id')->get()]);
    }

    public function configure(CommerceRequest $request, PaymentMethod $method, PaymentService $payments): mixed
    {
        $payments->configureMethod($request->user(), $method, $request->all());

        return to_route('payments.methods')->with('status', 'Receptor e instrucciones actualizados. Los pagos anteriores conservan su versión.');
    }

    public function declare(CommerceRequest $request, Order $order, PaymentService $payments): mixed
    {
        $request->validate(['proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        $payments->declare($request->user(), $order, $request->all(), $request->file('proof'));

        return to_route('commerce.orders.show', $order)->with('status', $request->hasFile('proof')
            ? 'Pago y comprobante recibidos. Administración verificará el abono en una sola revisión; te avisaremos aquí.'
            : 'Pago registrado. Administración verificará el abono en una sola revisión; te avisaremos aquí.');
    }

    public function proof(CommerceRequest $request, Payment $payment, PaymentService $payments): mixed
    {
        $request->validate(['proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        $payments->proof($request->user(), $payment, $request->all(), $request->file('proof'));

        return to_route('commerce.orders.show', $payment->order_id)->with('status', 'Comprobante recibido en almacenamiento privado; todavía no confirma el cobro.');
    }

    public function reopen(CommerceRequest $request, Payment $payment, PaymentService $payments): mixed
    {
        $request->validate(['proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        $payments->reopen($request->user(), $payment, $request->all(), $request->file('proof'));

        return to_route('commerce.orders.show', $payment->order_id)->with('status', 'Solicitaste una nueva revisión del mismo pago. Conservamos la operación y sus comprobantes; no vuelvas a transferir ese monto.');
    }

    public function download(Request $request, PaymentProof $proof, Commerce $commerce): mixed
    {
        $commerce->authorizeOrder($request->user(), $proof->payment->order);
        abort_unless(Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->download($proof->path, 'comprobante-'.$proof->id.'.'.pathinfo($proof->path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function review(CommerceRequest $request, Payment $payment, PaymentService $payments): mixed
    {
        $payments->reviewPayment($request->user(), $payment, $request->all());

        return to_route('commerce.orders.show', $payment->order_id)->with('status', 'Revisión del cobro registrada con responsable y constancia.');
    }

    public function refund(CommerceRequest $request, Payment $payment, PaymentService $payments): mixed
    {
        $payments->refund($request->user(), $payment, $request->all());

        return to_route('commerce.orders.show', $payment->order_id)->with('status', 'Devolución autorizada. Falta registrar y verificar su resultado real.');
    }

    public function refundOutcome(CommerceRequest $request, PaymentRefund $refund, PaymentService $payments): mixed
    {
        $payments->refundOutcome($request->user(), $refund, $request->all());

        return to_route('commerce.orders.show', $refund->payment->order_id)->with('status', 'Resultado de devolución registrado.');
    }

    public function calculate(CommerceRequest $request, Order $order, SettlementService $settlements): mixed
    {
        $settlements->calculate($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Liquidación calculada con el peso aceptado. Revisa y aprueba antes de autorizar desembolsos.');
    }

    public function approve(CommerceRequest $request, ProducerSettlement $settlement, SettlementService $settlements): mixed
    {
        $settlements->approve($request->user(), $settlement, $request->all());

        return to_route('commerce.orders.show', $settlement->order_id)->with('status', 'Liquidación aprobada; esto todavía no registra un pago al productor.');
    }

    public function payout(CommerceRequest $request, ProducerSettlement $settlement, SettlementService $settlements): mixed
    {
        $settlements->authorize($request->user(), $settlement, $request->all());

        return to_route('commerce.orders.show', $settlement->order_id)->with('status', 'Desembolso autorizado, pendiente de verificar su resultado.');
    }

    public function payoutOutcome(CommerceRequest $request, ProducerPayout $payout, SettlementService $settlements): mixed
    {
        $settlements->outcome($request->user(), $payout, $request->all());

        return to_route('commerce.orders.show', $payout->order_id)->with('status', 'Resultado del desembolso registrado con evidencia.');
    }

    public function defer(CommerceRequest $request, Order $order, PaymentService $payments): mixed
    {
        $payments->defer($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Pago diferido autorizado con límite y vencimiento; no registra dinero cobrado.');
    }

    public function close(CommerceRequest $request, Order $order, SettlementService $settlements): mixed
    {
        $settlements->close($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Orden cerrada con entrega, cobros, devoluciones y liquidaciones conciliados.');
    }
}
