<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class CommerceRecord extends Model
{
    protected array $mutable = [];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            $forbidden = array_diff(array_keys($record->getDirty()), [...$record->mutable, 'updated_at']);
            if ($forbidden !== []) {
                throw new LogicException('El registro comercial conserva su origen y condiciones. Utiliza una operación con historial.');
            }
        });
        static::deleting(fn () => throw new LogicException('Los registros comerciales no se eliminan.'));
    }
}
