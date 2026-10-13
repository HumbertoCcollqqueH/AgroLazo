<?php

namespace App\Contracts;

use App\Models\PaymentMethod;

interface PaymentGatewayInterface
{
    public function instructions(PaymentMethod $method, bool $synthetic): array;

    public function automaticallyConfirms(): bool;
}
