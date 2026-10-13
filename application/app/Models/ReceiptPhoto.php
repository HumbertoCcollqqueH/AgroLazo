<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collection_receipt_id', 'path', 'mime', 'size', 'sha256', 'recorded_by'])]
class ReceiptPhoto extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['path' => 'encrypted', 'size' => 'integer'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CollectionReceipt::class, 'collection_receipt_id');
    }
}
