<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'reason', 'occurred_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría no se modifica.'));
        static::deleting(fn () => throw new LogicException('La auditoría no se elimina.'));
    }

    /** @param array<string, mixed> $changes */
    public static function record(?User $actor, string $action, Model $subject, array $changes = [], ?string $reason = null): self
    {
        return static::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
