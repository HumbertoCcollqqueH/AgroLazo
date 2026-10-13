<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LotAvailability
{
    /** @return Collection<int, array{location: Location, label: string, ancestors: array<string, int>}> */
    public function geography(): Collection
    {
        $locations = Location::query()->orderBy('name')->get()->keyBy('id');
        $result = collect();
        foreach ($locations as $location) {
            $node = $location;
            $names = [];
            $ancestors = [];
            $seen = [];
            while ($node !== null && $node->active && ! isset($seen[$node->id])) {
                $seen[$node->id] = true;
                $names[] = $node->name;
                $ancestors[$node->type] = $node->id;
                if ($node->parent_id === null) {
                    if ($node->type === 'country') {
                        $result->put($location->id, ['location' => $location, 'label' => implode(' / ', array_reverse($names)), 'ancestors' => $ancestors]);
                    }
                    break;
                }
                $parent = $locations->get($node->parent_id);
                if ($parent === null || (Location::PARENT_TYPES[$node->type] ?? null) !== $parent->type) {
                    break;
                }
                $node = $parent;
            }
        }

        return $result;
    }

    public function eligibleOffers(?CarbonImmutable $at = null, ?Collection $geography = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return Offer::query()
            ->where('status', 'published')
            ->whereDate('available_until', '>=', $at->setTimezone('America/Lima')->toDateString())
            ->whereNotNull('normalized_quantity')
            ->whereHas('producer', fn (Builder $q) => $q->where('status', 'verified'))
            ->whereHas('product', fn (Builder $q) => $q->where('active', true)
                ->whereHas('category', fn (Builder $q) => $q->where('active', true))
                ->whereHas('baseUnit', fn (Builder $q) => $q->where('active', true)->whereIn('dimension', ['mass', 'count'])))
            ->whereHas('baseUnit', fn (Builder $q) => $q->where('active', true))
            ->whereHas('variety', fn (Builder $q) => $q->where('active', true))
            ->whereHas('qualityGrade', fn (Builder $q) => $q->where('active', true))
            ->whereIn('location_id', ($geography ?? $this->geography())->keys())
            ->whereRaw('normalized_quantity - withdrawn_quantity > 0')
            ->whereRaw('normalized_quantity - withdrawn_quantity >= (SELECT COALESCE(SUM(portion.quantity - portion.released_quantity), 0) FROM lot_offers portion WHERE portion.offer_id = offers.id)')
            ->whereHas('agreements', fn (Builder $q) => $q->whereColumn('offer_version', 'offers.version')
                ->where('status', 'accepted')->where('valid_until', '>', $at)
                ->where('currency', 'PEN')
                ->whereRaw("(valid_until AT TIME ZONE 'America/Lima')::date >= offers.available_from")
                ->whereRaw('version = (SELECT MAX(latest_agreement.version) FROM offer_price_agreements latest_agreement WHERE latest_agreement.offer_id = offers.id AND latest_agreement.offer_version = offers.version)'));
    }

    public function ungroupedOffers(?CarbonImmutable $at = null): Builder
    {
        return $this->eligibleOffers($at)
            ->select('offers.*')
            ->selectRaw('normalized_quantity - withdrawn_quantity - (SELECT COALESCE(SUM(portion.quantity - portion.released_quantity), 0) FROM lot_offers portion WHERE portion.offer_id = offers.id) AS ungrouped_quantity')
            ->whereRaw('normalized_quantity - withdrawn_quantity > (SELECT COALESCE(SUM(portion.quantity - portion.released_quantity), 0) FROM lot_offers portion WHERE portion.offer_id = offers.id)');
    }

    public function ungroupedQuantity(Offer $offer): string
    {
        $allocated = LotOffer::query()->where('offer_id', $offer->id)->selectRaw('COALESCE(SUM(quantity - released_quantity), 0) AS committed')->value('committed');

        return bcsub(bcsub($offer->normalized_quantity ?? '0', $offer->withdrawn_quantity, 3), (string) $allocated, 3);
    }

    public function eligiblePortions(?CarbonImmutable $at = null, ?Collection $geography = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return LotOffer::query()->whereIn('offer_id', $this->eligibleOffers($at, $geography)->select('offers.id'))
            ->whereHas('offer', fn (Builder $q) => $q->whereColumn('offers.version', 'lot_offers.offer_version'))
            ->whereHas('agreement', fn (Builder $q) => $q->whereColumn('offer_id', 'lot_offers.offer_id')
                ->whereColumn('offer_version', 'lot_offers.offer_version')
                ->where('status', 'accepted')->where('currency', 'PEN')->where('valid_until', '>', $at)
                ->whereRaw('version = (SELECT MAX(latest_agreement.version) FROM offer_price_agreements latest_agreement WHERE latest_agreement.offer_id = lot_offers.offer_id AND latest_agreement.offer_version = lot_offers.offer_version)'))
            ->whereHas('lot', fn (Builder $q) => $q->whereExists(function ($query): void {
                $query->selectRaw('1')->from('offers')->whereColumn('offers.id', 'lot_offers.offer_id');
                foreach (['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id'] as $field) {
                    $query->whereColumn('offers.'.$field, 'lots.'.$field);
                }
                $query->whereColumn('offers.available_from', '<=', 'lots.available_from')
                    ->whereColumn('offers.available_until', '>=', 'lots.available_until');
            }));
    }

    /** Validates the entire composition; an ineligible source hides the lot without releasing capacity. */
    public function eligibleLots(?CarbonImmutable $at = null, ?Collection $geography = null): Builder
    {
        $at ??= CarbonImmutable::now();
        $metrics = DB::table('lot_offers as portions')
            ->join('offers as sources', 'sources.id', '=', 'portions.offer_id')
            ->join('offer_price_agreements as prices', 'prices.id', '=', 'portions.agreement_id')
            ->whereRaw(AllocationService::availableSql('portions').' > 0')
            ->groupBy('portions.lot_id')
            ->selectRaw('portions.lot_id, SUM('.AllocationService::availableSql('portions').') AS available_quantity, COUNT(DISTINCT sources.producer_id) AS producer_count, MIN(prices.gross_unit_price) AS unit_price_min, MAX(prices.gross_unit_price) AS unit_price_max');

        return Lot::query()->joinSub($metrics, 'availability', 'availability.lot_id', '=', 'lots.id')
            ->select('lots.*', 'availability.available_quantity', 'availability.producer_count', 'availability.unit_price_min', 'availability.unit_price_max')
            ->whereDate('lots.available_until', '>=', $at->setTimezone('America/Lima')->toDateString())
            ->whereDoesntHave('portions', fn (Builder $q) => $q->whereRaw('quantity > released_quantity + retired_quantity')
                ->whereNotIn('lot_offers.id', $this->eligiblePortions($at, $geography)->select('lot_offers.id')));
    }

    public function marketplace(array $filters = [], ?Collection $geography = null): Builder
    {
        $geography ??= $this->geography();
        $today = CarbonImmutable::now('America/Lima')->toDateString();
        $query = $this->eligibleLots(geography: $geography)->where('lots.status', 'published')
            ->with(['product', 'variety', 'qualityGrade', 'location', 'baseUnit']);
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q']))).'%';
            $query->where(fn (Builder $query) => $query
                ->whereHas('product', fn (Builder $product) => $product->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$search]))
                ->orWhereHas('variety', fn (Builder $variety) => $variety->whereRaw("LOWER(product_varieties.name) LIKE ? ESCAPE '!'", [$search])));
        }
        foreach (['product_id', 'variety_id', 'quality_grade_id', 'base_unit_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('lots.'.$field, $filters[$field]);
            }
        }
        foreach (['region_id' => 'department', 'province_id' => 'province'] as $field => $type) {
            if (! empty($filters[$field])) {
                $query->whereIn('lots.location_id', $geography->filter(fn (array $entry): bool => ($entry['ancestors'][$type] ?? null) === (int) $filters[$field])->keys());
            }
        }
        foreach (['min_quantity' => ['available_quantity', '>='], 'min_price' => ['unit_price_min', '>='], 'max_price' => ['unit_price_max', '<=']] as $field => [$column, $operator]) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where('availability.'.$column, $operator, $filters[$field]);
            }
        }
        if (! empty($filters['date'])) {
            $query->whereDate('lots.available_from', '<=', $filters['date'])->whereDate('lots.available_until', '>=', $filters['date']);
        }
        if (($filters['availability'] ?? '') === 'now') {
            $query->whereDate('lots.available_from', '<=', $today);
        } elseif (($filters['availability'] ?? '') === 'upcoming') {
            $query->whereDate('lots.available_from', '>', $today);
        }
        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('availability.unit_price_min'),
            'quantity_desc' => $query->orderByDesc('availability.available_quantity'),
            'date_asc' => $query->orderBy('lots.available_from'),
            default => $query->orderByDesc('lots.published_at'),
        };

        return $query->orderBy('lots.id');
    }
}
