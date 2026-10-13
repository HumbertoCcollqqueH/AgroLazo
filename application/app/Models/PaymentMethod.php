<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['code', 'name', 'recipient', 'instructions', 'active', 'live_ready', 'version'])]
class PaymentMethod extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['recipient', 'instructions', 'active', 'live_ready', 'version'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'instructions' => 'encrypted', 'active' => 'boolean', 'live_ready' => 'boolean', 'version' => 'integer'];
    }
}
