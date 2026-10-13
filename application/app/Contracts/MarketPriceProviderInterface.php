<?php

namespace App\Contracts;

use App\Models\Lot;

interface MarketPriceProviderInterface
{
    public function reference(Lot $lot, bool $synthetic): ?array;
}
