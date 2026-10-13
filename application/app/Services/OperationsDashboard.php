<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\CollectionEvent;
use App\Models\CommerceEvent;
use App\Models\Conversation;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Producer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationsDashboard
{
    /**
     * Operational queues include earlier pending work. Date filters remain limited to the financial report summary.
     *
     * @param  array{data: string, product_id?: int|null, location_id?: int|null}  $filters
     * @return array{data: string, counts: array<string, int>, pendingOffers: Collection, pendingProducers: Collection, recentOrders: Collection, recentActivity: Collection, whatsapp: array{status: string, automation_enabled: bool}|null}
     */
    public function snapshot(User $actor, array $filters): array
    {
        $permissions = $actor->permissionCodes();
        abort_unless($permissions->contains('view-operations'), 403);
        $orders = $this->orders($filters);
        $canViewProducers = $permissions->contains('view-producers');
        $canViewOffers = $permissions->contains('view-offers');
        $canManageUsers = $permissions->contains('manage-users');
        $producerCounts = $canViewProducers ? Producer::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : collect();
        $offerCounts = $canViewOffers ? $this->dimensions(Offer::query(), $filters)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : collect();

        $connection = $canManageUsers ? WhatsAppConnection::query()->find(1, ['status', 'automation_enabled']) : null;
        $recentActivity = CommerceEvent::query()
            ->whereIn('order_id', (clone $orders)->select('id'))
            ->with('order:id,code')
            ->latest('occurred_at')->latest('id')->limit(5)
            ->get(['id', 'order_id', 'action', 'occurred_at'])
            ->map(fn (CommerceEvent $event): array => [
                'label' => config('commerce.event_labels.'.$event->action, 'Movimiento de una orden'),
                'order_code' => $event->order->code,
                'url' => route('commerce.orders.show', $event->order_id),
                'occurred_at' => $event->occurred_at,
            ]);

        return [
            'data' => $filters['data'],
            'counts' => [
                'producers_total' => (int) $producerCounts->sum(),
                'producers_pending' => (int) $producerCounts->get('pending_verification', 0),
                'offers_pending' => (int) $offerCounts->get('pending_verification', 0),
                'offers_verified' => (int) $offerCounts->get('verified', 0),
                'lots_published' => $permissions->contains('view-lots') ? $this->dimensions(Lot::query(), $filters)->where('status', 'published')->count() : 0,
                'orders_active' => (clone $orders)->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'users_active' => $canManageUsers ? User::query()->where('active', true)->count() : 0,
                'buyers_pending' => $permissions->contains('approve-buyers') ? Buyer::query()->where('status', 'pending')
                    ->whereHas('user', fn (Builder $query) => $query->where('active', true)->whereHas('roles', fn (Builder $roles) => $roles->where('code', 'buyer')))->count() : 0,
                'conversations_attention' => $permissions->contains('manage-messaging') ? Conversation::query()->where(fn (Builder $query) => $query->where('human_assigned', true)->orWhere('state', 'WAITING_IDENTITY_VERIFICATION'))->count() : 0,
                'payments_pending' => Payment::query()->whereIn('order_id', (clone $orders)->select('id'))->whereIn('status', ['pending', 'proof_uploaded', 'under_review'])->count(),
                'collections_scheduled' => CollectionEvent::query()->whereIn('order_id', (clone $orders)->select('id'))->where('status', 'scheduled')->count(),
            ],
            'pendingOffers' => $canViewOffers ? $this->dimensions(Offer::query(), $filters)
                ->where('status', 'pending_verification')
                ->with(['producer:id,full_name', 'product:id,name', 'variety:id,name', 'location:id,name', 'baseUnit:id,code'])
                ->oldest('created_at')->oldest('id')->limit(5)->get() : collect(),
            'pendingProducers' => $canViewProducers ? Producer::query()->where('status', 'pending_verification')
                ->oldest('created_at')->oldest('id')->limit(5)->get(['id', 'full_name', 'status', 'created_at']) : collect(),
            'recentOrders' => $orders->with(['buyer:id,user_id', 'buyer.user:id,name', 'items.baseUnit:id,code'])
                ->latest('confirmed_at')->latest('id')->limit(5)
                ->get(['id', 'code', 'buyer_id', 'status', 'confirmed_at', 'total_minor', 'currency', 'synthetic']),
            'recentActivity' => $recentActivity,
            'whatsapp' => $connection ? ['status' => $connection->status, 'automation_enabled' => $connection->automation_enabled] : null,
        ];
    }

    /** @param array{data: string, product_id?: int|null, location_id?: int|null} $filters */
    private function orders(array $filters): Builder
    {
        return Order::query()->where('synthetic', $filters['data'] === 'synthetic')
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('items.lot', fn (Builder $lots) => $lots->where('product_id', $id)))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('items.lot', fn (Builder $lots) => $lots->where('location_id', $id)));
    }

    /** @param array{product_id?: int|null, location_id?: int|null} $filters */
    private function dimensions(Builder $query, array $filters): Builder
    {
        return $query->when($filters['product_id'] ?? null, fn (Builder $query, int $id) => $query->where('product_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_id', $id));
    }
}
