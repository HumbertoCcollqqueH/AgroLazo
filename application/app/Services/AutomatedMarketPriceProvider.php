<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MarketPrice;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariety;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AutomatedMarketPriceProvider
{
    public function refresh(): array
    {
        $key = (string) config('market-pricing.cache_key');
        try {
            $failures = [];
            $prices = [];
            try {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(20)
                    ->get((string) config('market-pricing.source_url'))->throw();
                foreach ($this->parse($response->json()) as $code => $reference) {
                    $prices['quinua:'.$code] = $reference;
                }
            } catch (Throwable) {
                $failures[] = 'Agromercado no respondió con datos válidos.';
            }
            $sisap = app(SisapMarketSource::class)->refresh();
            $failures = [...$failures, ...array_values($sisap['failures'])];
            foreach ($sisap['prices'] as $code => $reference) {
                if (! isset($prices[$code]) || $reference['observed_on'] >= $prices[$code]['observed_on']) {
                    $prices[$code] = $reference;
                }
            }
            if ($prices === []) {
                throw new RuntimeException('La fuente no contiene observaciones válidas.');
            }
            $this->ensureCatalog();
            foreach ($this->snapshot()['prices'] as $code => $reference) {
                if (! isset($prices[$code]) || $reference['observed_on'] > $prices[$code]['observed_on']) {
                    $prices[$code] = $reference;
                }
            }
            $snapshot = ['checked_at' => now()->toIso8601String(), 'prices' => $prices];
            Cache::put($key, $snapshot, now()->addDays(14));
            Cache::forget($key.':error');
            if ($failures !== []) {
                Cache::put($key.':error', ['at' => now()->toIso8601String(), 'message' => implode(' ', $failures)], now()->addDays(14));
            }

            return $snapshot;
        } catch (Throwable $exception) {
            Cache::put($key.':error', ['at' => now()->toIso8601String(), 'message' => 'No se pudo actualizar la fuente oficial. Se conserva la última consulta y se comprueba su antigüedad.'], now()->addDays(14));
            throw $exception;
        }
    }

    public function parse(?array $payload): array
    {
        $dataset = $payload['grafico2_precioMayorista_quinua'] ?? null;
        if (! is_array($dataset) || ! is_array($dataset['labels'] ?? null)) {
            throw new RuntimeException('El formato de la fuente de precios cambió.');
        }
        $prices = [];
        foreach (config('market-pricing.series') as $series => $definition) {
            $values = $dataset[$series] ?? null;
            if (! is_array($values) || count($values) !== count($dataset['labels'])) {
                continue;
            }
            foreach ($dataset['labels'] as $index => $label) {
                $price = $values[$index];
                if (! is_string($label) || ! preg_match('/\A\d{2}\/\d{2}\/\d{2}\z/', $label)
                    || (! is_int($price) && ! is_float($price)) || $price <= 0 || $price > 100000) {
                    continue;
                }
                try {
                    $date = CarbonImmutable::createFromFormat('!d/m/y', $label, 'America/Lima');
                } catch (Throwable) {
                    continue;
                }
                if (! $date || $date->format('d/m/y') !== $label || $date->isAfter(now('America/Lima')->endOfDay())) {
                    continue;
                }
                $observed = $date->toDateString();
                if (isset($prices[$definition['code']]) && $prices[$definition['code']]['observed_on'] >= $observed) {
                    continue;
                }
                $prices[$definition['code']] = [
                    'unit_price' => number_format($price, 6, '.', ''), 'currency' => 'PEN',
                    'market' => 'Mercados mayoristas · Agromercado / SISAP',
                    'observed_on' => $observed, 'source' => config('market-pricing.source_page'),
                    'provider' => 'agromercado_sisap', 'product_label' => 'Quinua '.$definition['label'],
                ];
            }
        }

        return $prices;
    }

    public function forOffer(Offer $offer): ?array
    {
        if (! config('market-pricing.enabled')) {
            return null;
        }
        $manual = MarketPrice::query()->where($offer->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id']))
            ->where('unit_id', $offer->base_unit_id)->where('currency', 'PEN')->where('synthetic', false)
            ->whereBetween('observed_on', [$this->oldestDate(), now('America/Lima')->toDateString()])
            ->where('unit_price', '>', 0)->latest('observed_on')->latest('id')->first();
        if ($manual) {
            return ['unit_price' => $manual->unit_price, 'currency' => 'PEN', 'market' => $manual->market,
                'observed_on' => $manual->observed_on->toDateString(), 'source' => $manual->source,
                'provider' => 'manual', 'product_label' => $offer->product->name.' '.$offer->variety?->name];
        }
        if (! in_array($offer->product?->slug, ['quinua', 'papa'], true) || $offer->baseUnit?->code !== 'kg'
            || $offer->qualityGrade?->code !== 'unspecified') {
            return null;
        }
        $reference = $this->snapshot()['prices'][$offer->product->slug.':'.$offer->variety?->code]
            ?? ($offer->product->slug === 'quinua' ? ($this->snapshot()['prices'][$offer->variety?->code] ?? null) : null);

        return $reference && $this->isFresh($reference) ? $reference : null;
    }

    public function classificationChoices(Offer $offer): array
    {
        if (! config('market-pricing.enabled') || ! in_array($offer->product?->slug, ['quinua', 'papa'], true)
            || $offer->variety?->code !== 'unspecified' || $offer->baseUnit?->code !== 'kg') {
            return [];
        }

        return $offer->product->varieties()->where('active', true)
            ->whereIn('code', array_keys($this->varieties()[$offer->product->slug]))->orderBy('id')->get()
            ->map(fn (ProductVariety $variety): array => ['variety_id' => $variety->id, 'label' => $variety->name])->all();
    }

    public function snapshot(): array
    {
        return Cache::get((string) config('market-pricing.cache_key'), ['checked_at' => null, 'prices' => []]);
    }

    public function status(): array
    {
        $snapshot = $this->snapshot();

        return [...$snapshot, 'enabled' => (bool) config('market-pricing.enabled'),
            'error' => Cache::get(config('market-pricing.cache_key').':error'),
            'prices' => array_map(fn (array $price): array => [...$price, 'fresh' => $this->isFresh($price)], $snapshot['prices'])];
    }

    private function isFresh(array $reference): bool
    {
        return $reference['observed_on'] >= $this->oldestDate()
            && $reference['observed_on'] <= now('America/Lima')->toDateString();
    }

    private function oldestDate(): string
    {
        return now('America/Lima')->subDays((int) config('market-pricing.max_age_days', 7))->toDateString();
    }

    private function ensureCatalog(): void
    {
        foreach ($this->varieties() as $slug => $definitions) {
            $product = Product::query()->where('slug', $slug)->where('active', true)->first();
            if (! $product || $product->baseUnit->code !== 'kg') {
                continue;
            }
            DB::transaction(function () use ($product, $definitions): void {
                foreach ($definitions as $code => $label) {
                    $variety = ProductVariety::query()->firstOrCreate(['product_id' => $product->id, 'code' => $code], ['name' => $label, 'active' => true]);
                    if ($variety->wasRecentlyCreated) {
                        AuditLog::record(null, 'catalog.market_variety_added', $variety, ['source' => $product->slug === 'quinua' ? config('market-pricing.source_page') : SisapMarketSource::PORTAL], 'Variedad identificada en la fuente automática de precios.');
                    }
                }
            });
        }
    }

    private function varieties(): array
    {
        return [...SisapMarketSource::VARIETIES, 'quinua' => array_column(config('market-pricing.series'), 'label', 'code')];
    }
}
