<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Services\Commerce;
use App\Services\Money;
use App\Services\PaymentMethodQr;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PaymentExperienceController extends Controller
{
    public function inbox(Request $request): View
    {
        Gate::authorize('confirm-payments');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:pending,confirmed,rejected,all', 'method' => 'nullable|integer']);
        $pending = Payment::query()->whereIn('status', ['pending', 'proof_uploaded', 'under_review']);
        $payments = Payment::query()->with(['method', 'order.buyer.user', 'proofs']);
        $status = $filters['status'] ?? 'pending';
        if ($status === 'pending') {
            $payments->whereIn('status', ['pending', 'proof_uploaded', 'under_review']);
        } elseif ($status === 'confirmed') {
            $payments->whereIn('status', ['confirmed', 'partially_refunded', 'refunded']);
        } elseif ($status === 'rejected') {
            $payments->where('status', 'rejected');
        }
        if (filled($filters['q'] ?? null)) {
            $search = '%'.$filters['q'].'%';
            $payments->whereHas('order', fn ($query) => $query->where('code', 'ilike', $search)
                ->orWhereHas('buyer.user', fn ($user) => $user->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search)));
        }
        if (filled($filters['method'] ?? null)) {
            $payments->where('payment_method_id', $filters['method']);
        }

        return view('commerce.payment-inbox', ['payments' => $payments->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc')->orderBy('id')->paginate(15)->withQueryString(), 'pendingCount' => (clone $pending)->count(), 'pendingAmount' => (int) $pending->sum('amount_minor'), 'methods' => PaymentMethod::query()->orderBy('name')->get()]);
    }

    public function storeQr(CommerceRequest $request, PaymentMethod $method, PaymentMethodQr $qr): mixed
    {
        Gate::authorize('confirm-payments');
        $request->validate(['qr' => 'required|file']);
        $qr->store($request->user(), $method, $request->all(), $request->file('qr'));

        return to_route('payments.methods')->with('status', 'QR guardado para la cuenta receptora actual. Ya puede mostrarse en sus órdenes habilitadas.');
    }

    public function qr(Request $request, PaymentMethod $method, Commerce $commerce): mixed
    {
        abort_unless($method->hasCurrentQr() && $request->integer('version') === $method->version, 404);
        if (! $request->user()->hasPermission('confirm-payments')) {
            $order = Order::query()->findOrFail($request->integer('order'));
            $commerce->authorizeOrder($request->user(), $order);
            abort_unless(! $order->synthetic && ! in_array($order->status, ['closed', 'cancelled'], true) && $method->active && $method->live_ready, 404);
        }
        abort_unless(Storage::disk('local')->exists($method->qr_path), 404);

        return Storage::disk('local')->response($method->qr_path, 'agrolazo-'.$method->code.'.'.pathinfo($method->qr_path, PATHINFO_EXTENSION), ['Content-Type' => $method->qr_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function preview(Request $request, PaymentProof $proof, Commerce $commerce): mixed
    {
        $commerce->authorizeOrder($request->user(), $proof->payment->order);
        abort_unless(in_array($proof->mime, ['image/jpeg', 'image/png', 'image/webp'], true) && Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->response($proof->path, 'comprobante-'.$proof->id, ['Content-Type' => $proof->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function status(Request $request, Order $order, Commerce $commerce, PaymentService $service): JsonResponse
    {
        $commerce->authorizeOrder($request->user(), $order);

        return response()->json(self::summary($order, $service->balance($order)));
    }

    public static function summary(Order $order, array $balance): array
    {
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $pending = $payments->whereIn('status', ['pending', 'proof_uploaded', 'under_review']);
        $labels = ['pending' => 'Por verificar', 'proof_uploaded' => 'Comprobante recibido', 'under_review' => 'Por verificar', 'confirmed' => 'Ingreso confirmado', 'rejected' => 'Requiere corrección', 'partially_refunded' => 'Devolución parcial', 'refunded' => 'Devuelto'];
        $due = max(0, $balance['buyer_balance_minor']);
        $headline = $due === 0 ? 'Pago completo confirmado' : ($pending->isNotEmpty() ? 'Tu pago está por verificar' : 'Saldo por pagar');
        $hint = $due === 0 ? 'El ingreso está registrado. Sigue la preparación y entrega desde esta orden.' : ($pending->isNotEmpty() ? 'El comprobante ya está en la bandeja de administración. No repitas ese pago; te avisaremos al verificar el ingreso.' : 'Realiza un solo pago a AgroLazo y registra aquí su referencia y comprobante.');
        if ($due > 0 && $pending->isEmpty() && $payments->contains('status', 'rejected')) {
            $headline = 'Un registro necesita aclaración';
            $hint = 'No vuelvas a transferir ese monto. Solicita una nueva revisión del mismo pago a continuación o consulta con el equipo desde Conversaciones.';
        }
        if (in_array($order->status, ['closed', 'cancelled'], true)) {
            $headline = $order->status === 'closed' ? 'Orden cerrada' : 'Orden cancelada';
            $hint = 'Consulta aquí el historial de cobros y devoluciones. Para cualquier diferencia, abre una conversación con el equipo.';
        }

        return ['headline' => $headline, 'hint' => $hint, 'total' => Money::format($order->total_minor), 'received' => Money::format($balance['net_received_minor']), 'balance' => Money::format($due), 'settled' => $due === 0, 'closed' => in_array($order->status, ['closed', 'cancelled'], true), 'pending_count' => $pending->count(), 'pending' => Money::format((int) $pending->sum('amount_minor')), 'payments' => $payments->map(fn (Payment $payment) => ['id' => $payment->id, 'status' => $payment->status, 'label' => $labels[$payment->status] ?? $payment->status])->values()->all()];
    }
}
