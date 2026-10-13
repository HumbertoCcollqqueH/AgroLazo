<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Models\AuditLog;
use App\Models\CollectionEvent;
use App\Models\CollectionPoint;
use App\Models\CollectionReceipt;
use App\Models\Location;
use App\Models\LogisticsIncident;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\ReceiptPhoto;
use App\Models\Shipment;
use App\Models\Unit;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\Commerce;
use App\Services\LogisticsService;
use App\Services\OperationalNotifier;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class FulfillmentController extends Controller
{
    public function points(): View
    {
        Gate::authorize('manage-commerce');

        return view('commerce.points', ['points' => CollectionPoint::query()->with(['location', 'baseUnit'])->latest('id')->paginate(20), 'locations' => Location::query()->where('active', true)->get(), 'units' => Unit::query()->where('active', true)->whereIn('dimension', ['mass', 'count'])->get()]);
    }

    public function point(CommerceRequest $request, CollectionService $collection): mixed
    {
        $collection->point($request->user(), $request->all());

        return to_route('fulfillment.points')->with('status', 'Punto registrado con capacidad y horario diario en hora de Perú.');
    }

    public function schedule(CommerceRequest $request, Order $order, CollectionService $collection): mixed
    {
        $collection->schedule($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Acopio programado. Los avisos conservan la cantidad individual; su entrega depende del canal.');
    }

    public function receipt(CommerceRequest $request, CollectionEvent $event, LogisticsService $logistics): mixed
    {
        $receipt = $logistics->receipt($request->user(), $event, $request->all());

        return to_route('commerce.orders.show', $event->order_id)->with('status', 'Recepción #'.$receipt->id.' registrada con pesos y decisión de calidad.');
    }

    public function photo(CommerceRequest $request, CollectionReceipt $receipt, LogisticsService $logistics): mixed
    {
        $request->validate(['photo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $logistics->photo($request->user(), $receipt, $request->all(), $request->file('photo'));

        return to_route('commerce.orders.show', $receipt->event->order_id)->with('status', 'Fotografía guardada en almacenamiento privado.');
    }

    public function download(ReceiptPhoto $photo): mixed
    {
        Gate::authorize('manage-commerce');
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->download($photo->path, 'recepcion-'.$photo->collection_receipt_id.'.'.pathinfo($photo->path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function portions(CommerceRequest $request, string $field): array
    {
        if (! $request->has('selected')) {
            return $request->all();
        }
        $valid = $request->validate(['selected' => 'required|array|min:1|max:100', 'selected.*' => 'required|integer|distinct', 'quantities' => 'required|array|max:100']);
        $data = $request->all();
        $data['portions'] = array_map(fn ($id): array => [$field => (int) $id, 'quantity' => $valid['quantities'][$id] ?? null], $valid['selected']);

        return $data;
    }

    public function consolidate(CommerceRequest $request, Order $order, LogisticsService $logistics): mixed
    {
        $physical = $logistics->consolidate($request->user(), $order, $this->portions($request, 'receipt_id'));

        return to_route('commerce.orders.show', $order)->with('status', 'Lote físico '.$physical->code.' creado desde las recepciones aceptadas.');
    }

    public function shipment(CommerceRequest $request, Order $order, LogisticsService $logistics): mixed
    {
        $shipment = $logistics->shipment($request->user(), $order, $this->portions($request, 'physical_item_id'));

        return to_route('commerce.orders.show', $order)->with('status', 'Despacho '.$shipment->code.' preparado. La mercancía queda comprometida para esa salida.');
    }

    public function dispatch(CommerceRequest $request, Shipment $shipment, LogisticsService $logistics): mixed
    {
        $logistics->dispatch($request->user(), $shipment, $request->all());

        return to_route('commerce.orders.show', $shipment->order_id)->with('status', 'Salida registrada; la mercancía queda en tránsito.');
    }

    public function outcome(CommerceRequest $request, Shipment $shipment, LogisticsService $logistics): mixed
    {
        $logistics->outcome($request->user(), $shipment, $request->all());

        return to_route('commerce.orders.show', $shipment->order_id)->with('status', 'Movimiento registrado. Una devolución o pérdida no repone stock automáticamente.');
    }

    public function resolve(CommerceRequest $request, LogisticsIncident $incident, LogisticsService $logistics): mixed
    {
        $logistics->resolve($request->user(), $incident, $request->all());

        return to_route('commerce.orders.show', $incident->order_id)->with('status', 'Incidencia resuelta con constancia y conciliación de cantidades.');
    }

    public function retryNotice(CommerceRequest $request, OperationalNotice $notice, OperationalNotifier $notifier): mixed
    {
        $notifier->retry($request->user(), $notice, $request->all());

        return to_route('commerce.orders.show', $notice->order_id)->with('status', 'Se volverán a comprobar el contacto, consentimiento y estado del acopio antes de preparar el aviso.');
    }

    public function recoverNotices(CommerceRequest $request, Order $order, OperationalNotifier $notifier, Commerce $commerce): mixed
    {
        $count = $commerce->transaction($request->user(), 'manage-commerce', function (User $actor) use ($order, $notifier): int {
            $count = $notifier->recoverConfirmedOrders($order->id);
            AuditLog::record($actor, 'order.notices_recovered', $order, ['created' => $count]);

            return $count;
        });

        return to_route('commerce.orders.show', $order)->with('status', 'Avisos faltantes preparados: '.$count.'. Los avisos existentes no se duplican. Las compras de prueba solo usan el simulador.');
    }
}
