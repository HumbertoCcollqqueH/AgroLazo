<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommerceRequest;
use App\Http\Resources\QuoteResource;
use App\Models\CollectionEvent;
use App\Models\CollectionReceipt;
use App\Models\Location;
use App\Models\LogisticsIncident;
use App\Models\Lot;
use App\Models\MarketPrice;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PhysicalLot;
use App\Models\PricingPolicy;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\Unit;
use App\Notifications\CommerceNotification;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\CheckoutService;
use App\Services\CollectionService;
use App\Services\Commerce;
use App\Services\DeliveryAddress;
use App\Services\LotAvailability;
use App\Services\ManualMarketPriceProvider;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\PurchaseQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CommerceController extends Controller
{
    public function index(Request $request): View
    {
        $operator = $request->user()->hasPermission('manage-commerce');
        abort_unless($operator || $request->user()->hasPermission('view-buyer-area'), 403);
        $orders = Order::query()->when(! $operator, fn ($q) => $q->whereHas('buyer', fn ($q) => $q->where('user_id', $request->user()->id)))->latest('id')->paginate(20);
        $quotes = Quote::query()->when(! $operator, fn ($q) => $q->whereHas('buyer', fn ($q) => $q->where('user_id', $request->user()->id)))->latest('id')->limit(20)->get();

        $notifications = $request->user()->notifications()->where('type', CommerceNotification::class)->latest()->limit(15)->get();

        return view('commerce.index', compact('orders', 'quotes', 'operator', 'notifications'));
    }

    public function create(Request $request, Lot $lot, LotAvailability $availability): View|RedirectResponse
    {
        if ($request->user()->hasPermission('view-lots') && $request->user()->hasPermission('manage-commerce')) {
            return to_route('lots.show', $lot)->with('status', 'Estás en la vista de gestión del lote. Aquí puedes revisar sus productores, ubicaciones, aportaciones y publicación.');
        }
        Gate::authorize('place-orders');
        $geography = $availability->geography();
        $lot = $availability->marketplace(geography: $geography)->whereKey($lot->id)->first();
        if ($lot === null) {
            return to_route('marketplace.index')->with('status', 'Este lote ya no tiene disponibilidad para cotizar. Explora los lotes disponibles.');
        }
        $policies = PricingPolicy::query()->where('active', true)->where('base_unit_id', $lot->base_unit_id)->where('synthetic', ! config('commerce.live_enabled'))->get();
        $minimumQuantity = PurchaseQuantity::minimum($lot->baseUnit);
        $canQuote = PurchaseQuantity::allows($lot->baseUnit, (string) $lot->available_quantity);
        $requestedQuantity = $request->query('quantity');
        $quantity = is_string($requestedQuantity) && preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,3})?\z/', $requestedQuantity)
            && PurchaseQuantity::allows($lot->baseUnit, $requestedQuantity)
            && bccomp($requestedQuantity, (string) $lot->available_quantity, 3) <= 0
            && ($lot->baseUnit->dimension !== 'count' || bccomp($requestedQuantity, bcadd($requestedQuantity, '0', 0), 3) === 0)
            ? $requestedQuantity : ($minimumQuantity === null ? '' : PurchaseQuantity::format($minimumQuantity));
        $locationLabel = $geography->get($lot->location_id)['label'] ?? $lot->location->name;
        $destination = $request->user()->buyer?->delivery_address ?? ['recipient' => $request->user()->name, 'phone' => $request->user()->buyer?->phone ?? ''];

        return view('commerce.create', compact('lot', 'policies', 'destination', 'minimumQuantity', 'canQuote', 'quantity', 'locationLabel'));
    }

    public function quote(CommerceRequest $request, Lot $lot, PricingService $pricing): mixed
    {
        $quote = $pricing->issue($request->user(), $lot, $request->all());

        return $request->expectsJson() ? (new QuoteResource($quote))->response()->setStatusCode(201) : to_route('commerce.quotes.show', $quote)->with('status', 'Cotización emitida. Revisa el desglose antes de reservar o comprar.');
    }

    public function showQuote(Request $request, Quote $quote): mixed
    {
        abort_unless($request->user()->hasPermission('manage-commerce') || $quote->buyer->user_id === $request->user()->id, 403);
        if ($request->expectsJson()) {
            return new QuoteResource($quote);
        }
        $reservation = Reservation::query()->where('quote_id', $quote->id)->first();
        $order = Order::query()->where('quote_id', $quote->id)->first();

        return view('commerce.quote', compact('quote', 'reservation', 'order'));
    }

    public function reserve(CommerceRequest $request, Quote $quote, CheckoutService $checkout): mixed
    {
        $reservation = $checkout->reserve($request->user(), $quote, $request->all());

        return $request->expectsJson() ? response()->json(['id' => $reservation->id, 'status' => $reservation->status, 'expires_at' => $reservation->expires_at->toIso8601String()], 201) : to_route('commerce.quotes.show', $quote)->with('status', 'Reserva temporal creada. Su vencimiento se comprueba al confirmar.');
    }

    public function confirm(CommerceRequest $request, Quote $quote, CheckoutService $checkout): mixed
    {
        $order = $checkout->confirm($request->user(), $quote, $request->all());

        return $request->expectsJson() ? response()->json(['id' => $order->id, 'code' => $order->code, 'status' => $order->status, 'total_minor' => $order->total_minor, 'requested_delivery_on' => $order->requested_delivery_on?->toDateString()], 201) : to_route('commerce.orders.show', $order)->with('status', 'Compra confirmada. Se prepararon los avisos de la operación. Coordinación debe confirmar el punto y horario de acopio; el pago se verifica por separado.');
    }

    public function cancelReservation(CommerceRequest $request, Reservation $reservation, CheckoutService $checkout): mixed
    {
        $checkout->cancelReservation($request->user(), $reservation, $request->all());

        return to_route('commerce.quotes.show', $reservation->quote_id)->with('status', 'Reserva liberada.');
    }

    public function showOrder(Request $request, Order $order, Commerce $commerce): View
    {
        $commerce->authorizeOrder($request->user(), $order);
        $order->load(['items.allocations.portion.offer.producer', 'adjustments.items', 'events', 'quote']);

        $collections = CollectionEvent::query()->where('order_id', $order->id)->latest('id')->get();
        $collections->where('status', 'scheduled')->load('items.allocation.portion.offer.producer');
        $receipts = CollectionReceipt::query()->whereIn('collection_event_id', $collections->pluck('id'))->with('photos')->get();
        $physicalLots = PhysicalLot::query()->where('order_id', $order->id)->with('items')->get();
        $shipments = Shipment::query()->where('order_id', $order->id)->with(['items', 'events'])->get();
        $incidents = LogisticsIncident::query()->where('order_id', $order->id)->get();
        $notices = OperationalNotice::query()->where('order_id', $order->id)->with('message')->get();
        $canPlan = in_array($order->status, ['confirmed', 'collecting'], true) && $request->user()->hasPermission('manage-commerce');
        $suggestion = $canPlan ? app(CollectionService::class)->suggest($request->user(), $order) : null;
        $payments = Payment::query()->where('order_id', $order->id)->with(['method', 'proofs', 'refunds'])->get();
        $paymentMethods = in_array($order->status, ['closed', 'cancelled'], true) ? collect() : PaymentMethod::query()->where('active', true)->get();
        $financialBalance = app(PaymentService::class)->balance($order);
        $settlements = ProducerSettlement::query()->where('order_id', $order->id)->with(['producer', 'items'])->latest('version')->get();
        $payouts = ProducerPayout::query()->where('order_id', $order->id)->with('producer')->get();

        return view('commerce.order', compact('order', 'collections', 'receipts', 'physicalLots', 'shipments', 'incidents', 'notices', 'suggestion', 'payments', 'paymentMethods', 'financialBalance', 'settlements', 'payouts'));
    }

    public function deliveryAddress(CommerceRequest $request, Order $order, DeliveryAddress $addresses): mixed
    {
        $addresses->update($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Destino de entrega actualizado. Coordinación puede consultar la dirección y el punto exacto en el mapa.');
    }

    public function reduction(CommerceRequest $request, Order $order, CheckoutService $checkout): mixed
    {
        $checkout->proposeReduction($request->user(), $order, $request->all());

        return to_route('commerce.orders.show', $order)->with('status', 'Ajuste propuesto. El comprador debe aceptarlo antes de cambiar cantidades e importes.');
    }

    public function acceptReduction(CommerceRequest $request, OrderAdjustment $adjustment, CheckoutService $checkout): mixed
    {
        $checkout->acceptReduction($request->user(), $adjustment, $request->all());

        return to_route('commerce.orders.show', $adjustment->order_id)->with('status', 'Ajuste aceptado. Se conservan los importes y cantidades originales en el historial.');
    }

    public function pricing(): View
    {
        Gate::authorize('manage-pricing');

        return view('commerce.pricing', ['automaticPrices' => app(AutomatedMarketPriceProvider::class)->status(), 'policies' => PricingPolicy::query()->latest('id')->get(), 'references' => MarketPrice::query()->with(['product', 'unit'])->latest('id')->paginate(20), 'products' => Product::query()->where('active', true)->get(), 'varieties' => ProductVariety::all(), 'qualities' => QualityGrade::all(), 'locations' => Location::all(), 'units' => Unit::query()->where('active', true)->whereIn('dimension', ['mass', 'count'])->get()]);
    }

    public function policy(CommerceRequest $request, PricingService $pricing): mixed
    {
        $pricing->policy($request->user(), $request->all());

        return to_route('commerce.pricing')->with('status', 'Nueva regla de precios registrada.');
    }

    public function reference(CommerceRequest $request, ManualMarketPriceProvider $prices): mixed
    {
        $prices->record($request->user(), $request->all());

        return to_route('commerce.pricing')->with('status', 'Referencia manual guardada con fecha y fuente.');
    }
}
