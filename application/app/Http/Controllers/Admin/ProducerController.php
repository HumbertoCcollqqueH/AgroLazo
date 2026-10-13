<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeProducerPhoneRequest;
use App\Http\Requests\Admin\RecordProducerConsentRequest;
use App\Http\Requests\Admin\SaveProducerRequest;
use App\Http\Requests\Admin\VerifyProducerRequest;
use App\Models\Location;
use App\Models\Producer;
use App\Models\Product;
use App\Services\ProducerRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProducerController extends Controller
{
    public function __construct(private ProducerRegistry $registry) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Producer::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Producer::STATUSES))],
            'type' => ['nullable', Rule::in(array_keys(Producer::TYPES))],
        ]);
        $query = Producer::query()->with(['currentLocation.location', 'registeredBy']);
        if (($filters['q'] ?? '') !== '') {
            $query->where('full_name', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        foreach (['status', 'type'] as $filter) {
            if (($filters[$filter] ?? '') !== '') {
                $query->where($filter, $filters[$filter]);
            }
        }

        return view('producers.index', [
            'producers' => $query->orderBy('full_name')->orderBy('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Producer::class);

        return $this->form(null);
    }

    public function store(SaveProducerRequest $request): RedirectResponse
    {
        $producer = $this->registry->create($request->user(), $request->validated());

        return to_route('producers.show', $producer)->with('status', 'Productor registrado. Queda pendiente de verificación.');
    }

    public function show(Producer $producer): View
    {
        Gate::authorize('view', $producer);
        $producer->load([
            'registeredBy', 'currentLocation.location', 'verifications.verifiedBy',
            'locations.location', 'locations.recordedBy', 'consents.recordedBy', 'phoneChanges.changedBy',
            'profileChanges.changedBy', 'products.category', 'products.baseUnit',
            'verifications' => fn ($query) => $query->orderByDesc('verified_at')->orderByDesc('id'),
            'locations' => fn ($query) => $query->orderByDesc('valid_from')->orderByDesc('id'),
            'consents' => fn ($query) => $query->orderByDesc('recorded_at')->orderByDesc('id'),
            'phoneChanges' => fn ($query) => $query->orderByDesc('changed_at')->orderByDesc('id'),
            'profileChanges' => fn ($query) => $query->orderByDesc('changed_at')->orderByDesc('id'),
        ]);

        $allowedTransitions = ProducerRegistry::TRANSITIONS[$producer->status];
        $historyProductIds = $producer->profileChanges->flatMap(fn ($change): array => [
            ...($change->changes['product_ids']['before'] ?? []),
            ...($change->changes['product_ids']['after'] ?? []),
        ])->unique();
        $productNames = Product::query()->whereKey($historyProductIds)->pluck('name', 'id');

        return view('producers.show', compact('producer', 'allowedTransitions', 'productNames'));
    }

    public function edit(Producer $producer): View
    {
        Gate::authorize('update', $producer);

        return $this->form($producer);
    }

    public function update(SaveProducerRequest $request, Producer $producer): RedirectResponse
    {
        $producer = $this->registry->update($request->user(), $producer, $request->validated());

        return to_route('producers.show', $producer)->with('status', 'Ficha actualizada. Los cambios y la ubicación conservan su historial.');
    }

    public function verify(VerifyProducerRequest $request, Producer $producer): RedirectResponse
    {
        $producer = $this->registry->transition($request->user(), $producer, $request->validated());

        return to_route('producers.show', $producer)->with('status', 'Estado actualizado. La revisión quedó registrada con su responsable.');
    }

    public function changePhone(ChangeProducerPhoneRequest $request, Producer $producer): RedirectResponse
    {
        $producer = $this->registry->changePhone($request->user(), $producer, $request->validated());

        return to_route('producers.show', $producer)->with('status', 'Teléfono actualizado mediante cambio asistido. Revisa el estado de verificación.');
    }

    public function consent(RecordProducerConsentRequest $request, Producer $producer): RedirectResponse
    {
        $producer = $this->registry->recordConsent($request->user(), $producer, $request->validated());

        return to_route('producers.show', $producer)->with('status', 'Decisión registrada para la finalidad y versión del aviso indicadas.');
    }

    private function form(?Producer $producer): View
    {
        $producer?->load(['currentLocation.location', 'products']);
        $currentLocationId = $producer?->currentLocation?->location_id;
        $locations = Location::query()->with('parent')->orderBy('name')->get()
            ->filter(fn (Location $location): bool => $location->id === $currentLocationId || $location->isUsable());
        $currentProductIds = $producer?->products->modelKeys() ?? [];
        $products = Product::query()->with(['category', 'baseUnit'])->orderBy('name')->get()
            ->filter(fn (Product $product): bool => in_array($product->id, $currentProductIds, true) || $product->isUsable());

        return view('producers.form', compact('producer', 'locations', 'products'));
    }
}
