<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['code', 'name', 'recipient', 'instructions', 'active', 'live_ready', 'version', 'qr_path', 'qr_mime', 'qr_sha256', 'qr_recipient_version'])]
class PaymentMethod extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['recipient', 'instructions', 'active', 'live_ready', 'version', 'qr_path', 'qr_mime', 'qr_sha256', 'qr_recipient_version'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'instructions' => 'encrypted', 'active' => 'boolean', 'live_ready' => 'boolean', 'version' => 'integer', 'qr_recipient_version' => 'integer'];
    }

    public function hasCurrentQr(): bool
    {
        return filled($this->qr_path) && $this->qr_recipient_version === $this->version;
    }
}
