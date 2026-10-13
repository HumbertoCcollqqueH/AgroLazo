<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'key', 'payload_hash', 'report', 'format', 'filters', 'status', 'path', 'sha256', 'row_count', 'generated_at', 'expires_at', 'failure_code'])]
#[Hidden(['path', 'filters', 'payload_hash'])]
class ReportExport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['filters' => 'encrypted:array', 'row_count' => 'integer', 'generated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
