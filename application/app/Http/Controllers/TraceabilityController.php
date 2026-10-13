<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Http\Resources\TraceResource;
use App\Models\Lot;
use App\Models\Order;
use App\Models\PhysicalLot;
use App\Models\TracePublication;
use App\Services\QrCode;
use App\Services\TraceabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TraceabilityController extends Controller
{
    public function manage(Request $request, Lot $lot, TraceabilityService $trace): View
    {
        Gate::authorize('manage-traceability');
        $physical = $request->filled('physical_lot_id') ? PhysicalLot::query()->where('lot_id', $lot->id)->findOrFail($request->integer('physical_lot_id')) : null;

        return view('commerce.trace-manage', ['lot' => $lot, 'physical' => $physical, 'physicalLots' => PhysicalLot::query()->where('lot_id', $lot->id)->get(), 'preview' => $trace->preview($request->user(), $lot, $physical), 'publications' => TracePublication::query()->where('lot_id', $lot->id)->latest('id')->get()]);
    }

    public function publish(CommerceRequest $request, Lot $lot, TraceabilityService $trace): mixed
    {
        $trace->publish($request->user(), $lot, $request->all());

        return to_route('trace.manage', $lot)->with('status', 'Ficha pública creada. El QR identifica esta publicación; puedes revocarla.');
    }

    public function revoke(CommerceRequest $request, TracePublication $publication, TraceabilityService $trace): mixed
    {
        $trace->revoke($request->user(), $publication, $request->all());

        return to_route('trace.manage', $publication->lot_id)->with('status', 'Enlace y QR revocados.');
    }

    public function show(Request $request, string $token, TraceabilityService $trace): mixed
    {
        $publication = $trace->resolve($token);
        $resource = new TraceResource($publication);

        return ($request->expectsJson() || $request->is('api/*')) ? $resource : view('commerce.trace-public', ['trace' => $resource->resolve($request)]);
    }

    public function qr(string $token, TraceabilityService $trace, QrCode $qr): Response
    {
        $publication = $trace->resolve($token);

        return response($qr->svg($trace->publicUrl($publication->token)), 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'inline; filename="agrolazo-trace.svg"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox"]);
    }

    public function chain(Request $request, Order $order, TraceabilityService $trace): mixed
    {
        $chain = $trace->chain($request->user(), $order);

        return ($request->expectsJson() || $request->is('api/*')) ? response()->json(['order' => $order->code, 'chain' => $chain]) : view('commerce.trace-chain', compact('order', 'chain'));
    }
}
