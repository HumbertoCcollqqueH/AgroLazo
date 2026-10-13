<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveLotRequest;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Product;
use App\Services\AggregationService;
use App\Services\CollectionProximity;
use App\Services\LotAvailability;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LotController extends Controller
{
    public function __construct(private AggregationService $aggregation, private LotAvailability $availability) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-lots');
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Lot::STATUSES))]]);
        $lots = Lot::query()->with(['product', 'baseUnit', 'location'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(15)->withQueryString();

        return view('lots.index', compact('lots'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('manage-lots');
        $filters = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'anchor_id' => 'nullable|integer|exists:offers,id',
            'allow_price_variation' => 'sometimes|boolean',
        ]);
        $suggested = ! empty($filters['anchor_id']);
        $offers = $suggested
            ? $this->aggregation->suggest($request->user(), Offer::findOrFail($filters['anchor_id']), (bool) ($filters['allow_price_variation'] ?? false))
            : $this->availability->ungroupedOffers()->with(['producer', 'product', 'variety', 'qualityGrade', 'location', 'baseUnit'])
                ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
                ->orderBy('available_from')->orderBy('id')->paginate(30)->withQueryString();
        $rows = collect($offers->all())->map(function (Offer $offer): array {
            return ['offer' => $offer, 'agreement' => $offer->activeAgreement()];
        })->filter(fn (array $row): bool => $row['agreement'] !== null);

        return view('lots.form', [
            'offers' => $offers, 'rows' => $rows, 'suggested' => $suggested,
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'geography' => $this->availability->geography(),
        ]);
    }

    public function store(SaveLotRequest $request): RedirectResponse
    {
        $lot = $this->aggregation->create($request->user(), $request->validated());

        return to_route('lots.show', $lot)->with('status', 'Lote creado en borrador. Sus porciones quedaron separadas; revisa la composición antes de publicar.');
    }

    public function show(Lot $lot): View
    {
        Gate::authorize('view-lots');
        $lot->load(['product', 'variety', 'qualityGrade', 'location', 'baseUnit', 'portions.offer.producer.currentLocation.location', 'portions.agreement', 'histories.actor', 'createdBy']);
        $marketable = $this->availability->eligibleLots()->whereKey($lot->id)->first();
        $activePortions = $lot->portions->filter(fn ($portion): bool => bccomp($portion->quantity, bcadd($portion->released_quantity, $portion->retired_quantity, 3), 3) > 0);
        $proximity = app(CollectionProximity::class);
        $originInspection = $proximity->inspect($activePortions->pluck('offer'));
        $originPoints = $activePortions->map(function ($portion) use ($proximity): ?array {
            $point = $proximity->coordinates($portion->offer);
            if ($point === null) {
                return null;
            }

            return ['latitude' => $point[0], 'longitude' => $point[1], 'label' => $portion->offer->producer->full_name, 'offer_id' => $portion->offer_id];
        })->filter()->values();

        return view('lots.show', [
            'lot' => $lot, 'marketable' => $marketable,
            'originInspection' => $originInspection, 'originPoints' => $originPoints,
            'transitions' => AggregationService::TRANSITIONS[$lot->status],
            'locationLabel' => $this->availability->geography()->get($lot->location_id)['label'] ?? $lot->location->name,
        ]);
    }

    public function transition(Request $request, Lot $lot): RedirectResponse
    {
        $this->aggregation->transition($request->user(), $lot, $request->all());

        return to_route('lots.show', $lot)->with('status', 'Estado del lote actualizado con historial.');
    }
}
