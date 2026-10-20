<?php

namespace App\Services;

use App\Jobs\SyncWhatsAppContact;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\ProducerVerification;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProducerRegistry
{
    public const TRANSITIONS = [
        'pending_verification' => ['verified', 'rejected', 'archived'],
        'rejected' => ['pending_verification', 'archived'],
        'verified' => ['suspended', 'archived'],
        'suspended' => ['verified', 'archived'],
        'archived' => [],
    ];

    private const PROFILE_FIELDS = ['full_name', 'type', 'association', 'email', 'sex', 'birth_date'];

    private const LOCATION_FIELDS = ['location_id', 'community', 'address', 'latitude', 'longitude'];

    public function __construct(private ProducerIdentity $identity) {}

    public static function profileRules(bool $updating): array
    {
        return [
            'full_name' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'min:3', 'max:150'],
            'dni' => $updating ? ['prohibited'] : ['required', 'string', 'regex:/^[0-9]{8}$/D'],
            'phone' => $updating ? ['prohibited'] : ['required', 'string', 'max:30'],
            'type' => [$updating ? 'sometimes' : 'required', 'required', Rule::in(array_keys(Producer::TYPES))],
            'association' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'sex' => ['nullable', Rule::in(['female', 'male', 'unspecified'])],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Lima')->toDateString()],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'community' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90', 'regex:/^-?[0-9]{1,3}(?:\.[0-9]{1,6})?$/D'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180', 'regex:/^-?[0-9]{1,3}(?:\.[0-9]{1,6})?$/D'],
            'reason' => $updating ? ['required', 'string', 'min:5', 'max:500'] : ['prohibited'],
            'status' => ['prohibited'], 'registered_by' => ['prohibited'],
            'dni_lookup' => ['prohibited'], 'phone_lookup' => ['prohibited'],
            'photo_path' => ['prohibited'], 'verified_by' => ['prohibited'], 'verified_at' => ['prohibited'],
        ];
    }

    public static function verificationRules(): array
    {
        return [
            'result' => ['required', Rule::in(array_keys(Producer::STATUSES))],
            'method' => ['required', Rule::in(array_keys(ProducerVerification::METHODS))],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
            'verified_by' => ['prohibited'], 'verified_at' => ['prohibited'], 'producer_id' => ['prohibited'],
        ];
    }

    public static function phoneRules(): array
    {
        return [
            'current_phone' => ['required', 'string', 'max:30'],
            'new_phone' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'changed_by' => ['prohibited'], 'changed_at' => ['prohibited'], 'producer_id' => ['prohibited'],
        ];
    }

    public static function consentRules(): array
    {
        return [
            'purpose' => ['required', Rule::in(array_keys(ProducerConsent::PURPOSES))],
            'notice_version' => ['required', 'string', 'max:80'],
            'decision' => ['required', Rule::in(['granted', 'declined'])],
            'recorded_by' => ['prohibited'], 'recorded_at' => ['prohibited'],
            'channel' => ['prohibited'], 'producer_id' => ['prohibited'],
        ];
    }

    public function create(User $actor, array $data): Producer
    {
        return $this->write($actor, 'manage-producers', function (User $actor) use ($data): Producer {
            $data = Validator::make($data, self::profileRules(false))->validate();
            $phone = $this->identity->normalizePhone($data['phone']);
            $this->requireUniqueIdentity('dni', $data['dni']);
            $this->requireUniqueIdentity('phone', $phone);
            $producer = Producer::query()->create([
                ...Arr::only($data, self::PROFILE_FIELDS),
                'dni' => $data['dni'], 'phone' => $phone,
                'status' => 'pending_verification', 'registered_by' => $actor->id,
            ]);
            $this->saveLocation($actor, $producer, $data);
            $productChange = $this->saveProducts($producer, $data);
            AuditLog::record($actor, 'producer.created', $producer, [
                'status' => 'pending_verification', 'changed_fields' => [...self::PROFILE_FIELDS, 'dni', 'phone', ...($productChange ? ['product_ids'] : [])],
            ], 'assisted_registration');

            if (config('queue.default') === 'redis') {
                SyncWhatsAppContact::dispatch($producer->id)->afterCommit();
            }

            return $producer->fresh();
        });
    }

    public function registerSelf(User $actor, array $data): Producer
    {
        return DB::transaction(function () use ($actor, $data): Producer {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->active && $actor->hasRole('producer') && ! $actor->producer()->exists(), 403);
            $this->identity->assertKeyMatchesDatabase();
            $data = Validator::make($data, self::profileRules(false))->validate();
            $phone = $this->identity->normalizePhone($data['phone']);
            $this->requireUniqueIdentity('dni', $data['dni']);
            $this->requireUniqueIdentity('phone', $phone);
            $producer = new Producer([...Arr::only($data, self::PROFILE_FIELDS), 'dni' => $data['dni'], 'phone' => $phone,
                'status' => 'pending_verification', 'registered_by' => $actor->id]);
            $producer->user_id = $actor->id;
            $producer->save();
            $this->saveLocation($actor, $producer, $data);
            $this->saveProducts($producer, $data);
            AuditLog::record($actor, 'producer.self_registered', $producer, ['status' => $producer->status], 'self_service');

            return $producer;
        }, 3);
    }

    public function updateOwnLocation(User $actor, array $data): Producer
    {
        return DB::transaction(function () use ($actor, $data): Producer {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->active && $actor->hasRole('producer'), 403);
            $producer = $actor->producer()->lockForUpdate()->firstOrFail();
            $allowed = [...self::LOCATION_FIELDS, 'product_ids', 'product_ids.*'];
            $data = Validator::make($data, Arr::only(self::profileRules(true), $allowed))->validate();
            $this->requireValid(! in_array($producer->status, ['archived', 'suspended'], true), 'profile', 'Tu ficha requiere atención de coordinación.');
            $this->saveLocation($actor, $producer, $data);
            $this->saveProducts($producer, $data);
            AuditLog::record($actor, 'producer.own_location_updated', $producer, [], 'self_service');

            return $producer->fresh();
        }, 3);
    }

    public function update(User $actor, Producer $producer, array $data): Producer
    {
        return $this->write($actor, 'manage-producers', function (User $actor) use ($producer, $data): Producer {
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            Gate::forUser($actor)->authorize('update', $producer);
            $data = Validator::make($data, self::profileRules(true))->validate();
            $previousStatus = $producer->status;
            $previousProfile = $this->profileValues($producer);
            $producer->fill(Arr::only($data, self::PROFILE_FIELDS));
            $changes = [];
            foreach ($this->profileValues($producer) as $field => $value) {
                if ($previousProfile[$field] !== $value) {
                    $changes[$field] = ['before' => $previousProfile[$field], 'after' => $value];
                }
            }
            $identityChanged = isset($changes['full_name']) || isset($changes['type']);
            if ($producer->status === 'rejected' || ($producer->status === 'verified' && $identityChanged)) {
                $producer->status = 'pending_verification';
            }
            if ($previousStatus !== $producer->status) {
                $changes['status'] = ['before' => $previousStatus, 'after' => $producer->status];
            }
            if ($changes !== []) {
                $producer->save();
            }
            $locationChange = $this->saveLocation($actor, $producer, $data);
            if ($locationChange !== null) {
                $changes['location_version_id'] = $locationChange;
            }
            $productChange = $this->saveProducts($producer, $data);
            if ($productChange !== null) {
                $changes['product_ids'] = $productChange;
            }
            if ($changes === []) {
                return $producer->fresh();
            }
            if ($previousStatus !== $producer->status) {
                $this->addVerification($actor, $producer, 'administrative', $data['reason']);
            }
            $producer->profileChanges()->create([
                'changes' => $changes, 'reason' => $data['reason'],
                'changed_by' => $actor->id, 'changed_at' => now(),
            ]);
            AuditLog::record($actor, 'producer.updated', $producer, [
                'changed_fields' => array_keys($changes),
                'status' => ['before' => $previousStatus, 'after' => $producer->status],
            ], 'profile_correction');

            return $producer->fresh();
        });
    }

    public function transition(User $actor, Producer $producer, array $data): Producer
    {
        return $this->write($actor, 'verify-producers', function (User $actor) use ($producer, $data): Producer {
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            Gate::forUser($actor)->authorize('verify', $producer);
            $data = Validator::make($data, self::verificationRules())->validate();
            $this->requireValid(in_array($data['result'], self::TRANSITIONS[$producer->status], true),
                'result', 'El cambio solicitado no está permitido para el estado actual.');
            $previousStatus = $producer->status;
            $producer->status = $data['result'];
            $producer->save();
            $this->addVerification($actor, $producer, $data['method'], $data['notes']);
            if ($user = $producer->user) {
                ProducerPortal::notify($user, 'Tu ficha de productor: '.Producer::STATUSES[$producer->status].'. '.$data['notes'], route('producer.home'), 'verification:'.$producer->id.':'.$producer->verifications()->max('id'));
            }
            AuditLog::record($actor, 'producer.status_changed', $producer, [
                'status' => ['before' => $previousStatus, 'after' => $producer->status], 'method' => $data['method'],
            ], 'verification_decision');

            return $producer->fresh();
        });
    }

    public function changePhone(User $actor, Producer $producer, array $data): Producer
    {
        return $this->write($actor, 'manage-producers', function (User $actor) use ($producer, $data): Producer {
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            Gate::forUser($actor)->authorize('update', $producer);
            $data = Validator::make($data, self::phoneRules())->validate();
            $currentPhone = $this->normalizePhoneForField($data['current_phone'], 'current_phone');
            $newPhone = $this->normalizePhoneForField($data['new_phone'], 'new_phone');
            $this->requireValid(hash_equals($producer->phone, $currentPhone), 'current_phone', 'El teléfono actual no coincide con la ficha.');
            $this->requireValid($newPhone !== $currentPhone, 'new_phone', 'Indica un teléfono distinto del actual.');
            $this->requireUniqueIdentity('phone', $newPhone, $producer->id, 'new_phone');
            $previousStatus = $producer->status;
            $producer->phone = $newPhone;
            if (in_array($producer->status, ['verified', 'rejected'], true)) {
                $producer->status = 'pending_verification';
            }
            $producer->save();
            $producer->phoneChanges()->create([
                'previous_phone' => $currentPhone, 'new_phone' => $newPhone,
                'reason' => $data['reason'], 'changed_by' => $actor->id, 'changed_at' => now(),
            ]);
            if ($previousStatus !== $producer->status) {
                $this->addVerification($actor, $producer, 'administrative', $data['reason']);
            }
            AuditLog::record($actor, 'producer.phone_changed', $producer, [
                'changed_fields' => ['phone'], 'status' => ['before' => $previousStatus, 'after' => $producer->status],
            ], 'assisted_phone_change');

            if (config('queue.default') === 'redis') {
                SyncWhatsAppContact::dispatch($producer->id)->afterCommit();
            }

            return $producer->fresh();
        });
    }

    public function recordConsent(User $actor, Producer $producer, array $data): Producer
    {
        return $this->write($actor, 'manage-producers', function (User $actor) use ($producer, $data): Producer {
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            $data = Validator::make($data, self::consentRules())->validate();
            $producer->consents()->create([
                ...Arr::only($data, ['purpose', 'notice_version', 'decision']),
                'channel' => 'assisted_web', 'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
            AuditLog::record($actor, 'producer.consent_recorded', $producer, [
                'purpose' => $data['purpose'], 'decision' => $data['decision'],
            ], 'consent_recorded');

            return $producer->fresh();
        });
    }

    private function write(User $actor, string $permission, Closure $callback): Producer
    {
        try {
            return DB::transaction(function () use ($actor, $permission, $callback): Producer {
                Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
                $actor = User::query()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize($permission);
                $this->identity->assertKeyMatchesDatabase();

                return $callback($actor);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }
            $field = str_contains($exception->getMessage(), 'dni_lookup') ? 'dni' : 'phone';
            throw ValidationException::withMessages([$field => 'Ya existe una ficha con ese documento o teléfono.']);
        }
    }

    private function requireUniqueIdentity(string $kind, string $value, ?int $exceptId = null, ?string $field = null): void
    {
        $duplicate = Producer::query()->where($kind.'_lookup', $this->identity->lookup($kind, $value))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->exists();
        $this->requireValid(! $duplicate, $field ?? $kind,
            $kind === 'dni' ? 'Este DNI ya tiene una ficha de productor.' : 'Este teléfono ya está asociado a otro productor.');
    }

    private function addVerification(User $actor, Producer $producer, string $method, string $notes): void
    {
        $producer->verifications()->create([
            'result' => $producer->status, 'method' => $method, 'notes' => $notes,
            'verified_by' => $actor->id, 'verified_at' => now(),
        ]);
    }

    /** @return array<string, string|null> */
    private function profileValues(Producer $producer): array
    {
        $values = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $value = $producer->getAttribute($field);
            $values[$field] = $field === 'birth_date' ? $value?->format('Y-m-d') : $value;
        }

        return $values;
    }

    /** @return array{before: int|null, after: int|null}|null */
    private function saveLocation(User $actor, Producer $producer, array $data): ?array
    {
        if (array_intersect(self::LOCATION_FIELDS, array_keys($data)) === []) {
            return null;
        }
        $current = $producer->currentLocation()->lockForUpdate()->first();
        $locationData = [];
        $changed = false;
        foreach (self::LOCATION_FIELDS as $field) {
            $previous = $current?->getAttribute($field);
            $value = array_key_exists($field, $data) ? $data[$field] : $previous;
            if (is_string($value)) {
                $value = trim($value);
            }
            $value = $value === '' ? null : $value;
            $locationData[$field] = $value;
            $same = $previous === null && $value === null;
            if ($previous !== null && $value !== null) {
                $same = in_array($field, ['latitude', 'longitude'], true)
                    ? bccomp((string) $previous, (string) $value, 6) === 0
                    : (string) $previous === (string) $value;
            }
            $changed = $changed || ! $same;
        }
        if (! $changed) {
            return null;
        }
        $this->requireValid(($locationData['latitude'] === null) === ($locationData['longitude'] === null),
            'latitude', 'Indica latitud y longitud juntas, o deja ambas vacías.');
        if ($locationData['location_id'] !== null) {
            $location = Location::query()->find($locationData['location_id']);
            $this->requireValid($location?->isUsable() ?? false, 'location_id', 'Selecciona una localidad activa con su jerarquía disponible.');
        }
        $timestamp = now();
        if ($current) {
            $current->update(['valid_until' => $timestamp]);
        }
        $nextLocation = null;
        if (count(array_filter($locationData, fn ($value): bool => $value !== null)) > 0) {
            $nextLocation = $producer->locations()->create([
                ...$locationData, 'recorded_by' => $actor->id, 'valid_from' => $timestamp,
            ]);
        }

        return ['before' => $current?->id, 'after' => $nextLocation?->id];
    }

    private function normalizePhoneForField(string $value, string $field): string
    {
        try {
            return $this->identity->normalizePhone($value);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => $exception->errors()['phone'] ?? 'Indica un teléfono válido.']);
        }
    }

    /** @return array{before: list<int>, after: list<int>}|null */
    private function saveProducts(Producer $producer, array $data): ?array
    {
        if (! array_key_exists('product_ids', $data)) {
            return null;
        }

        $previous = $producer->products()->orderBy('products.id')->pluck('products.id')->all();
        $selected = array_map('intval', $data['product_ids']);
        sort($selected);
        if ($previous === $selected) {
            return null;
        }

        $added = array_diff($selected, $previous);
        $products = Product::query()->with(['category', 'baseUnit'])->whereKey($added)->get();
        $this->requireValid($products->count() === count($added)
            && $products->every(fn (Product $product): bool => $product->isUsable()),
            'product_ids', 'Selecciona productos activos con su categoría y unidad disponibles.');
        $producer->products()->sync($selected);

        return ['before' => $previous, 'after' => $selected];
    }

    private function requireValid(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
