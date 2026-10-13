<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\PricingPolicy;
use App\Models\Quote;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PricingService
{
    public function __construct(private Commerce $commerce, private AllocationService $allocation, private ManualMarketPriceProvider $references) {}

    public function issue(User $actor, Lot $lot, array $data): Quote
    {
        return $this->commerce->transaction($actor, 'place-orders', function (User $actor) use ($lot, $data): Quote {
            $data = Validator::make($data, [...DeliveryAddress::rules(), 'key' => 'required|uuid', 'quantity' => 'required|string', 'policy_id' => 'required|integer|exists:pricing_policies,id', 'delivery_details' => 'required|string|min:5|max:2000', 'payment_method' => ['required', Rule::in(array_keys(config('commerce.payment_methods')))]])->validate();
            $data['quantity'] = Commerce::quantity($data['quantity'], $lot->baseUnit->dimension);
            PurchaseQuantity::validate($lot->baseUnit, $data['quantity']);
            $data['lot_id'] = $lot->id;

            return $this->commerce->once($actor, 'quote', $data, function () use ($actor, $lot, $data): Quote {
                $lot = $this->commerce->lockLot($lot);
                $at = Commerce::clock();
                $policy = PricingPolicy::query()->findOrFail($data['policy_id']);
                Commerce::ensure($policy->active && $policy->base_unit_id === $lot->base_unit_id, 'policy_id', 'La regla de precio ya no está disponible para esta unidad.');
                Commerce::ensure($policy->synthetic ? app()->environment(['local', 'testing']) : (bool) config('commerce.live_enabled'), 'policy_id', 'La operación real necesita habilitación y condiciones comerciales revisadas.');
                $planned = $this->allocation->plan($lot, $data['quantity'], $policy->allocation_rule, $at);
                $subtotal = 0;
                $items = [];
                $expires = $at->addMinutes((int) config('commerce.quote_minutes'));
                $expires = $expires->min(CarbonImmutable::parse($lot->available_until->toDateString(), 'America/Lima')->endOfDay());
                foreach ($planned as $candidate) {
                    $portion = $candidate['portion'];
                    $agreement = $portion->agreement;
                    $gross = Money::line($candidate['quantity'], $agreement->gross_unit_price);
                    $cost = Money::line($candidate['quantity'], $agreement->producer_unit_cost);
                    Commerce::ensure($cost <= $gross, 'price', 'Los costos del productor exceden su bruto.');
                    $subtotal += $gross;
                    $items[] = ['lot_offer_id' => $portion->id, 'agreement_id' => $agreement->id, 'offer_version' => $portion->offer_version, 'quantity' => $candidate['quantity'], 'gross_unit_price' => $agreement->gross_unit_price, 'producer_unit_cost' => $agreement->producer_unit_cost, 'gross_minor' => $gross, 'producer_cost_minor' => $cost, 'producer_net_minor' => $gross - $cost, 'terms_snapshot' => $agreement->terms];
                    $expires = $expires->min($agreement->valid_until);
                }
                $fee = Money::line($data['quantity'], $policy->fee_unit_price);
                $logistics = 0;
                $discount = bccomp($data['quantity'], $policy->discount_threshold, 3) >= 0 ? $policy->discount_minor : 0;
                Commerce::ensure($discount <= $fee, 'policy_id', 'El descuento financiado por la plataforma no puede reducir el producto ni la logística.');
                $taxable = $subtotal + $fee + $logistics - $discount;
                $tax = (int) bcadd(bcdiv(bcmul((string) $taxable, (string) $policy->tax_bps, 0), '10000', 6), '0.5', 0);
                $total = $taxable + $tax;
                Commerce::ensure($total <= 1000000000000, 'total', 'El importe excede el límite admitido.');
                $quote = Quote::query()->create([
                    'code' => 'Q-'.Str::upper(Str::random(10)), 'buyer_id' => $actor->buyer->id, 'lot_id' => $lot->id, 'lot_version' => $lot->version,
                    'pricing_policy_id' => $policy->id, 'quantity' => $data['quantity'], 'base_unit_id' => $lot->base_unit_id, 'currency' => 'PEN',
                    'subtotal_minor' => $subtotal, 'fee_minor' => $fee, 'logistics_minor' => $logistics, 'discount_minor' => $discount, 'tax_minor' => $tax, 'total_minor' => $total,
                    'weighted_unit_price' => bcdiv(bcdiv((string) $subtotal, '100', 8), $data['quantity'], 6),
                    'pricing_version' => 'platform-transport-v2', 'pricing_snapshot' => [...$policy->only(['id', 'name', 'allocation_rule', 'fee_unit_price', 'discount_threshold', 'discount_minor', 'tax_bps', 'tax_basis', 'disclosure', 'synthetic']), 'logistics_minor' => 0, 'delivery_mode' => 'delivery', 'transport_paid_by' => 'platform'],
                    'delivery_address' => $data['delivery_address'], 'reference_snapshot' => $this->references->reference($lot, $policy->synthetic), 'delivery_details' => $data['delivery_details'],
                    'delivery_mode' => 'delivery', 'payment_method' => $data['payment_method'], 'synthetic' => $policy->synthetic,
                    'status' => 'issued', 'issued_at' => $at, 'expires_at' => $expires,
                ]);
                $quote->items()->createMany($items);
                $this->commerce->event($actor, 'quote_issued', null, $lot, ['quote_id' => $quote->id, 'quantity' => $quote->quantity]);

                return $quote;
            });
        });
    }

    public function policy(User $actor, array $data): PricingPolicy
    {
        return $this->commerce->transaction($actor, 'manage-pricing', function (User $actor) use ($data): PricingPolicy {
            $data = Validator::make($data, [
                'key' => 'required|uuid', 'name' => 'required|string|max:160', 'base_unit_id' => 'required|integer|exists:units,id', 'allocation_rule' => 'required|in:fifo,lowest_price',
                'fee_unit_price' => ['required', 'string', 'regex:/\A[0-9]{1,8}(?:\.[0-9]{1,6})?\z/'],
                'logistics_minor' => 'required|integer|min:0|max:1000000000', 'discount_threshold' => ['required', 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,3})?\z/'],
                'discount_minor' => 'required|integer|min:0|max:1000000000', 'tax_bps' => 'required|integer|min:0|max:10000',
                'tax_basis' => 'required|string|min:10|max:2000', 'disclosure' => 'required|string|min:10|max:4000',
                'delivery_mode' => 'required|in:collection,delivery', 'synthetic' => 'required|boolean',
            ])->validate();
            Commerce::ensure(Unit::query()->whereKey($data['base_unit_id'])->where('active', true)->whereIn('dimension', ['mass', 'count'])->exists(), 'base_unit_id', 'Selecciona una unidad base activa.');

            return $this->commerce->once($actor, 'pricing-policy', $data, fn (): PricingPolicy => PricingPolicy::query()->create([...array_diff_key($data, ['key' => true]), 'recorded_by' => $actor->id]));
        });
    }
}
