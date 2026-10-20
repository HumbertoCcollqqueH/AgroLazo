<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OfferReview
{
    public function __construct(private OfferRegistry $registry, private ProducerPortal $portal) {}

    public function approve(User $actor, Offer $offer, array $data): ?OfferPriceAgreement
    {
        return DB::transaction(function () use ($actor, $offer, $data): ?OfferPriceAgreement {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('manage-offers');
            Gate::forUser($actor)->authorize('manage-lots');
            $choice = Validator::make($data, ['price_mode' => ['sometimes', 'required', Rule::in(['automatic', 'manual'])]])->validate();
            $offer = $this->registry->transition($actor, $offer, [
                ...Arr::only($data, ['version', 'reason']), 'result' => 'verified',
            ]);

            if (($choice['price_mode'] ?? 'automatic') === 'manual') {
                return $this->registry->proposeAgreement($actor, $offer, Arr::only($data, [
                    'version', 'gross_unit_price', 'producer_unit_cost', 'valid_until', 'terms',
                ]));
            }

            return $this->portal->propose($offer, $actor);
        }, 3);
    }
}
