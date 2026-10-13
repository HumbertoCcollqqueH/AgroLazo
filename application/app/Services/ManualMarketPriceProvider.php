<?php

namespace App\Services;

use App\Contracts\MarketPriceProviderInterface;
use App\Models\Lot;
use App\Models\MarketPrice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class ManualMarketPriceProvider implements MarketPriceProviderInterface
{
    public function reference(Lot $lot, bool $synthetic): ?array
    {
        $prices = MarketPrice::query()->where($lot->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id']))
            ->where('unit_id', $lot->base_unit_id)->where('currency', 'PEN')->where('synthetic', $synthetic)
            ->whereBetween('observed_on', [now('America/Lima')->subDays(14)->toDateString(), now('America/Lima')->toDateString()])
            ->orderBy('unit_price')->orderBy('id')->limit(100)->get();
        if ($prices->isEmpty()) {
            return null;
        }
        $sum = $prices->reduce(fn (string $sum, MarketPrice $price): string => bcadd($sum, $price->unit_price, 6), '0');
        $middle = intdiv($prices->count(), 2);
        $median = $prices->count() % 2 ? $prices[$middle]->unit_price : bcdiv(bcadd($prices[$middle - 1]->unit_price, $prices[$middle]->unit_price, 6), '2', 6);

        return ['provider' => 'manual', 'synthetic' => $synthetic, 'is_official' => false, 'minimum' => $prices->first()->unit_price, 'maximum' => $prices->last()->unit_price, 'average' => bcdiv($sum, (string) $prices->count(), 6), 'median' => $median, 'currency' => 'PEN', 'count' => $prices->count(),
            'sources' => $prices->map(fn (MarketPrice $p): array => ['id' => $p->id, 'market' => $p->market, 'source' => $p->source, 'observed_on' => $p->observed_on->toDateString(), 'unit_price' => $p->unit_price])->all()];
    }

    public function record(User $actor, array $data): MarketPrice
    {
        return app(Commerce::class)->transaction($actor, 'manage-pricing', function (User $actor) use ($data): MarketPrice {
            $data = Validator::make($data, ['key' => 'required|uuid', 'product_id' => 'required|integer|exists:products,id', 'variety_id' => 'required|integer|exists:product_varieties,id', 'quality_grade_id' => 'required|integer|exists:quality_grades,id', 'location_id' => 'required|integer|exists:locations,id', 'unit_id' => 'required|integer|exists:units,id', 'unit_price' => ['required', 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,6})?\z/'], 'market' => 'required|string|max:160', 'source' => 'required|string|min:10|max:2000', 'observed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'synthetic' => 'required|boolean'])->validate();
            $product = Product::query()->findOrFail($data['product_id']);
            Commerce::ensure($product->varieties()->whereKey($data['variety_id'])->exists() && $product->qualityGrades()->whereKey($data['quality_grade_id'])->exists() && $product->base_unit_id === (int) $data['unit_id'], 'product_id', 'La referencia debe tener clasificación y unidad base compatibles.');
            Commerce::ensure(bccomp($data['unit_price'], '0', 6) > 0, 'unit_price', 'El precio debe ser positivo.');

            return app(Commerce::class)->once($actor, 'market-price', $data, fn (): MarketPrice => MarketPrice::query()->create([...array_diff_key($data, ['key' => true]), 'currency' => 'PEN', 'provider' => 'manual', 'recorded_by' => $actor->id]));
        });
    }
}
