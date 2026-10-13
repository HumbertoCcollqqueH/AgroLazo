<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CommerceEvent;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\OperationKey;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Commerce
{
    public static function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public static function quantity(mixed $value, string $dimension = 'mass'): string
    {
        self::ensure(is_string($value) && (bool) preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,3})?\z/', $value), 'quantity', 'Indica una cantidad con hasta tres decimales.');
        $quantity = bcadd($value, '0', 3);
        self::ensure(bccomp($quantity, '0', 3) > 0, 'quantity', 'La cantidad debe ser positiva.');
        self::ensure($dimension !== 'count' || bccomp($quantity, bcadd($quantity, '0', 0), 3) === 0, 'quantity', 'La unidad exige cantidades enteras.');

        return $quantity;
    }

    public static function clock(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()'));
    }

    /** All commercial writers share the catalog/access/inventory mutex before row locks. */
    public function transaction(?User $actor, ?string $ability, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $ability, $callback): mixed {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            if ($actor) {
                $actor = User::query()->findOrFail($actor->id);
                abort_unless($actor->active, 403);
                if ($ability) {
                    Gate::forUser($actor)->authorize($ability);
                }
            }

            return $callback($actor, self::clock());
        }, 3);
    }

    /** Run only inside transaction() so concurrent retries see one persisted outcome. */
    public function once(User $actor, string $operation, array $data, Closure $callback): Model
    {
        Validator::make($data, ['key' => 'required|uuid'])->validate();
        $payload = $data;
        unset($payload['key'], $payload['_token']);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = OperationKey::query()->where(['actor_id' => $actor->id, 'operation' => $operation, 'key' => $data['key']])->first();
        if ($existing) {
            self::ensure(hash_equals($existing->payload_hash, $hash), 'key', 'Esta referencia ya se utilizó con otros datos. Inicia una operación nueva.');

            return $existing->resource_type::query()->findOrFail($existing->resource_id);
        }
        $result = $callback();
        OperationKey::query()->create(['actor_id' => $actor->id, 'operation' => $operation, 'key' => $data['key'], 'payload_hash' => $hash, 'resource_type' => $result::class, 'resource_id' => $result->getKey()]);

        return $result;
    }

    public function lockLot(Lot $lot): Lot
    {
        $offerIds = $lot->portions()->pluck('offer_id');
        Offer::query()->whereKey($offerIds)->orderBy('id')->lockForUpdate()->get();
        LotOffer::query()->whereIn('offer_id', $offerIds)->orderBy('id')->lockForUpdate()->get();

        return Lot::query()->lockForUpdate()->findOrFail($lot->id);
    }

    public function authorizeOrder(User $actor, Order $order, bool $operator = false): void
    {
        abort_unless($actor->active && ($actor->hasPermission('manage-commerce') || (! $operator && $order->buyer->user_id === $actor->id)), 403);
    }

    public function event(?User $actor, string $action, ?Order $order, ?Lot $lot, array $details = []): CommerceEvent
    {
        $event = CommerceEvent::query()->create(['actor_id' => $actor?->id, 'order_id' => $order?->id, 'lot_id' => $lot?->id, 'action' => $action, 'details' => $details, 'occurred_at' => self::clock()]);
        AuditLog::record($actor, 'commerce.'.$action, $order ?? $lot ?? $event, ['event_id' => $event->id], 'commerce_operation');
        if ($order !== null) {
            app(OperationalNotifier::class)->buyer($event);
            app(OperationalNotifier::class)->producers($event);
            app(OperationalNotifier::class)->coordinators($event);
        }

        return $event;
    }
}
