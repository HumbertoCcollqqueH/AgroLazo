<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'integration', 'instance_name', 'status', 'automation_enabled', 'notice_text', 'notice_version', 'notice_approved', 'revision', 'last_error', 'updated_by'])]
class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    protected function casts(): array
    {
        return ['automation_enabled' => 'boolean', 'notice_approved' => 'boolean', 'revision' => 'integer'];
    }

    public function isQr(): bool
    {
        return $this->integration === 'WHATSAPP-BAILEYS';
    }
}
