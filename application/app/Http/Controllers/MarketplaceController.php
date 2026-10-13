<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceFilterRequest;
use App\Http\Resources\MarketplaceLotResource;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Services\BuyerLotProximity;
use App\Services\LotAvailability;
use App\Services\ProductVisual;
use App\Services\PurchaseQuantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    public function __construct(private LotAvailability $availability) {}

    public function index(MarketplaceFilterRequest $request): View
    {
        $filters = $request->validated();
        $geography = $this->availability->geography();
        $products = Product::query()->where('active', true)
            ->whereHas('category', fn (Builder $query) => $query->where('active', true))
            ->whereHas('baseUnit', fn (Builder $query) => $query->where('active', true)->whereIn('dimension', ['mass', 'count']))
            ->orderBy('name')->get();
        $availableLots = $this->availability->marketplace(geography: $geography)->reorder()->select('lots.product_id')->setEagerLoads([]);
        $productCounts = DB::query()->fromSub($availableLots, 'available_lots')->selectRaw('product_id, COUNT(*) AS aggregate')->groupBy('product_id')->pluck('aggregate', 'product_id');
        $availableLotCount = (int) $productCounts->sum();
        $hasFilters = collect($filters)->except(['sort', 'page'])->contains(fn (mixed $value): bool => $value !== null && $value !== '');
        $featuredOrder = array_flip(ProductVisual::FEATURED_PRODUCTS);
        $categoryProducts = $products->sortBy(fn (Product $product): int => $featuredOrder[$product->slug] ?? count($featuredOrder))
            ->take(10)->values()->each(fn (Product $product) => $product->setAttribute('available_lots_count', (int) $productCounts->get($product->id, 0)));
        $proximity = app(BuyerLotProximity::class);
        $destination = $proximity->destination($request->user());
        $effectiveSort = $filters['sort'] ?? ($destination ? 'nearest' : 'newest');
        $catalog = $this->availability->marketplace($filters, $geography);
        if ($destination !== null) {
            $proximity->apply($catalog, $destination, $effectiveSort === 'nearest');
        }

        return view('marketplace.index', [
            'lots' => $catalog->paginate(12, total: $hasFilters ? null : $availableLotCount)->withQueryString(),
            'hasDestination' => $destination !== null, 'effectiveSort' => $effectiveSort,
            'products' => $products,
            'categoryProducts' => $categoryProducts,
            'availableLotCount' => $availableLotCount,
            'varieties' => ProductVariety::query()->join('products', 'products.id', '=', 'product_varieties.product_id')->where('products.active', true)->where('product_varieties.active', true)->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_varieties.product_id', $id))->orderBy('product_varieties.name')->get(['product_varieties.*', 'products.name as product_name']),
            'qualities' => QualityGrade::query()->join('products', 'products.id', '=', 'quality_grades.product_id')->where('products.active', true)->where('quality_grades.active', true)->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('quality_grades.product_id', $id))->orderBy('quality_grades.name')->get(['quality_grades.*', 'products.name as product_name']),
            'units' => Unit::query()->where('active', true)->whereIn('dimension', ['mass', 'count'])->orderBy('name')->get(),
            'geography' => $geography,
        ]);
    }

    public function show(Request $request, string $code): View
    {
        $geography = $this->availability->geography();
        $lot = $this->availability->marketplace(geography: $geography)->where('lots.code', $code)->firstOrFail();
        $quantity = null;
        $estimate = null;
        $quantityError = null;
        if ($request->has('quantity')) {
            try {
                $data = Validator::make($request->only('quantity'), ['quantity' => ['required', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/']])->validate();
                $quantity = bcadd($data['quantity'], '0', 3);
                PurchaseQuantity::validate($lot->baseUnit, $quantity);
                if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, (string) $lot->available_quantity, 3) > 0) {
                    throw ValidationException::withMessages(['quantity' => 'Indica una cantidad positiva que no supere la disponibilidad actual.']);
                }
                if ($lot->baseUnit->dimension === 'count' && bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                    throw ValidationException::withMessages(['quantity' => 'Este producto se expresa en unidades enteras.']);
                }
                $estimate = [
                    'minimum' => bcadd(bcmul($quantity, (string) $lot->unit_price_min, 9), '0.005', 2),
                    'maximum' => bcadd(bcmul($quantity, (string) $lot->unit_price_max, 9), '0.005', 2),
                ];
            } catch (ValidationException $exception) {
                $quantityError = collect($exception->errors())->flatten()->first();
            }
        }

        return view('marketplace.show', [
            'lot' => $lot, 'quantity' => $quantity, 'estimate' => $estimate, 'quantityError' => $quantityError,
            'locationLabel' => $geography->get($lot->location_id)['label'] ?? $lot->location->name,
        ]);
    }

    public function apiIndex(MarketplaceFilterRequest $request): AnonymousResourceCollection
    {
        return MarketplaceLotResource::collection($this->availability->marketplace($request->validated())->paginate(12)->withQueryString());
    }

    public function apiShow(string $code): MarketplaceLotResource
    {
        return new MarketplaceLotResource($this->availability->marketplace()->where('lots.code', $code)->firstOrFail());
    }
}
