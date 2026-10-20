<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Producer;
use App\Models\User;
use Illuminate\Support\Collection;

class ConversationContext
{
    /** @return array<string, mixed>|null */
    public function forParticipant(User $actor, User $participant): ?array
    {
        $permissions = $actor->permissionCodes();
        if (! $permissions->contains('view-operations')) {
            return null;
        }
        $producer = $permissions->contains('view-producers') ? $participant->producer()->with(['products', 'currentLocation.location'])->first() : null;
        $buyer = $participant->buyer()->first();
        $context = [
            'name' => $participant->name, 'active' => $participant->active,
            'role' => $producer ? 'Productor' : ($buyer ? 'Comprador' : 'Cuenta AgroLazo'),
            'account_url' => $permissions->contains('manage-users') ? route('admin.users.index', ['q' => $participant->email]) : null,
            'producer' => null, 'buyer' => null, 'pending' => [], 'offers' => [], 'offer_count' => 0, 'orders' => [], 'active_order_count' => 0,
        ];
        if (! $participant->active) {
            $context['pending'][] = ['label' => 'La cuenta está deshabilitada; administración debe revisar su acceso.', 'url' => $context['account_url']];
        }
        if ($producer) {
            $this->producer($context, $producer, $permissions);
        }
        if ($buyer) {
            $this->buyer($context, $buyer);
        }
        if ($permissions->contains('manage-commerce') && ($producer || $buyer)) {
            $this->orders($context, $producer, $buyer);
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Collection<int, string>  $permissions
     */
    private function producer(array &$context, Producer $producer, Collection $permissions): void
    {
        $location = $producer->currentLocation;
        $profileUrl = route('producers.show', $producer);
        $context['producer'] = [
            'name' => $producer->full_name,
            'status' => Producer::STATUSES[$producer->status] ?? $producer->status,
            'products' => $producer->products->pluck('name')->all(),
            'location' => collect([$location?->location?->name, $location?->community, $location?->address])->filter()->unique()->implode(' · '),
            'has_coordinates' => $location?->latitude !== null && $location?->longitude !== null,
            'url' => $profileUrl,
        ];
        if ($producer->status !== 'verified') {
            $context['pending'][] = ['label' => 'Ficha del productor: '.(Producer::STATUSES[$producer->status] ?? $producer->status).'. Revisa su habilitación.', 'url' => $profileUrl];
        }
        if ($producer->products->isEmpty()) {
            $context['pending'][] = ['label' => 'Falta indicar los productos que produce.', 'url' => $profileUrl];
        }
        if (! $location || blank($location->address) || ! $context['producer']['has_coordinates']) {
            $context['pending'][] = ['label' => 'Completar la dirección y el punto de recojo en el mapa.', 'url' => $profileUrl];
        }
        if (! $permissions->contains('view-offers')) {
            return;
        }
        $query = Offer::query()->where('producer_id', $producer->id);
        $context['offer_count'] = (clone $query)->count();
        $counts = (clone $query)->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');
        foreach (['pending_verification' => 'ofertas por revisar', 'needs_information' => 'ofertas con datos por completar'] as $status => $label) {
            if (($count = (int) $counts->get($status, 0)) > 0) {
                $offerId = (clone $query)->where('status', $status)->latest('id')->value('id');
                $context['pending'][] = ['label' => $count.' '.$label.'.', 'url' => route('offers.show', $offerId)];
            }
        }
        $offers = $query->with(['product', 'variety', 'unit', 'baseUnit', 'agreements' => fn ($query) => $query->orderByDesc('version')])
            ->orderByRaw("CASE WHEN status IN ('pending_verification', 'needs_information', 'verified') THEN 0 WHEN status = 'published' THEN 1 ELSE 2 END")
            ->latest('id')->limit(6)->get();
        $context['offers'] = $offers->map(fn (Offer $offer): array => $this->offer($offer))->all();
    }

    /** @return array<string, mixed> */
    private function offer(Offer $offer): array
    {
        $agreement = $offer->agreements->firstWhere('offer_version', $offer->version);
        $live = $agreement && $agreement->valid_until->isFuture();
        $terminal = in_array($offer->status, ['cancelled', 'rejected', 'expired'], true);
        $next = match (true) {
            $offer->status === 'cancelled' => 'Cancelada; no admite nuevas compras.',
            $offer->status === 'expired' => 'Disponibilidad terminada; preparar una nueva oferta si hay más producción.',
            $offer->status === 'rejected' => 'El productor puede corregir los datos y enviarlos de nuevo.',
            $offer->status === 'draft' => 'El productor debe completar y enviar la oferta.',
            $offer->status === 'pending_verification' => 'Coordinación debe revisar los datos de la oferta.',
            $offer->status === 'needs_information' => 'El productor debe completar la información solicitada.',
            $offer->status === 'published' && $live && $agreement->status === 'accepted' => 'Publicada; consulta sus aportaciones y disponibilidad.',
            $agreement && ! $live => 'El precio venció; revisar una propuesta vigente antes de nuevas compras.',
            $live && $agreement->status === 'proposed' => 'El productor debe aceptar el precio o pedir un cambio.',
            $live && $agreement->status === 'accepted' => 'Precio aceptado; revisar su publicación y agrupación.',
            $agreement?->status === 'rejected' => 'El precio no fue aceptado; coordinar una nueva propuesta.',
            default => 'Falta definir un precio compatible con el producto y su variedad.',
        };

        return [
            'id' => $offer->id, 'product' => $offer->product->name, 'variety' => $offer->variety?->name,
            'quantity' => PurchaseQuantity::format((string) $offer->quantity).' '.$offer->unit->code,
            'status' => Offer::STATUSES[$offer->status] ?? $offer->status,
            'availability' => $offer->available_from->format('d/m/Y').' al '.$offer->available_until->format('d/m/Y'),
            'price' => ! $terminal && $live && in_array($agreement->status, ['proposed', 'accepted'], true)
                ? ($agreement->status === 'accepted' ? 'Precio aceptado' : 'Precio propuesto').': '.$agreement->currency.' '.number_format((float) $agreement->net_unit_price, 2).' netos/'.$offer->baseUnit->code
                : null,
            'next' => $next, 'url' => route('offers.show', $offer),
        ];
    }

    /** @param array<string, mixed> $context */
    private function buyer(array &$context, Buyer $buyer): void
    {
        $destination = $buyer->delivery_address ?? [];
        $context['buyer'] = [
            'type' => Buyer::TYPES[$buyer->type] ?? $buyer->type,
            'status' => ['pending' => 'Pendiente de aprobación', 'approved' => 'Aprobado', 'suspended' => 'Suspendido'][$buyer->status] ?? $buyer->status,
            'destination' => collect(['street', 'district', 'province', 'department'])->map(fn (string $key) => $destination[$key] ?? null)->filter()->unique()->implode(' · '),
        ];
        if ($buyer->status !== 'approved') {
            $context['pending'][] = ['label' => 'Comprador: '.$context['buyer']['status'].'. Administración debe revisar su habilitación.', 'url' => $context['account_url']];
        }
        if (collect(['recipient', 'phone', 'department', 'province', 'district', 'street', 'reference', 'latitude', 'longitude'])->contains(fn (string $key) => ! isset($destination[$key]) || $destination[$key] === '')) {
            $context['pending'][] = ['label' => 'Falta completar el destino habitual en el perfil del comprador. Cada compra conserva su propio destino.', 'url' => null];
        }
    }

    /** @param array<string, mixed> $context */
    private function orders(array &$context, ?Producer $producer, ?Buyer $buyer): void
    {
        $orders = Order::query()->where(function ($query) use ($producer, $buyer): void {
            if ($buyer) {
                $query->where('buyer_id', $buyer->id);
            }
            if ($producer) {
                $query->orWhereHas('items.allocations.portion.offer', fn ($query) => $query->where('producer_id', $producer->id));
            }
        });
        $context['active_order_count'] = (clone $orders)->whereNotIn('status', ['cancelled', 'closed'])->count();
        $context['orders'] = $orders->with('items')->withCount(['adjustments as pending_adjustments' => fn ($query) => $query->where('status', 'proposed')])
            ->orderByRaw("CASE WHEN status IN ('cancelled', 'closed') THEN 1 ELSE 0 END")->latest('id')->limit(6)->get()
            ->map(fn (Order $order): array => [
                'code' => $order->code,
                'status' => config('commerce.status_labels.'.$order->status, $order->status),
                'products' => $order->items->map(fn ($item) => $item->product_snapshot['product'] ?? 'Producto')->unique()->implode(', '),
                'delivery' => $order->requested_delivery_on?->format('d/m/Y'),
                'next' => $order->pending_adjustments > 0 ? 'Hay un ajuste pendiente de aceptación del comprador.' : match ($order->status) {
                    'confirmed' => 'Coordinar el acopio y revisar el cobro.',
                    'collecting' => 'Completar las recepciones y el pesaje.',
                    'ready' => 'Revisar el pago y programar el despacho.',
                    'in_transit' => 'Confirmar la entrega y registrar cualquier incidencia.',
                    'delivered' => 'Revisar liquidaciones y saldos para cerrar la operación.',
                    'cancelled' => 'Compra cancelada; revisar ajustes y devoluciones.',
                    'closed' => 'Operación cerrada.',
                    default => 'Revisar el seguimiento de la operación.',
                },
                'url' => route('commerce.orders.show', $order),
            ])->all();
    }
}
