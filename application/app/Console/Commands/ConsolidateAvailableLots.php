<?php

namespace App\Console\Commands;

use App\Models\Lot;
use App\Models\User;
use App\Services\AutomaticLotGrouping;
use App\Services\LotAvailability;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lots:consolidate {--actor= : Usuario responsable con permiso de gestionar lotes} {--lot= : Limitar a un lote de origen} {--apply : Aplicar; sin esta opción solo muestra la propuesta} {--automatic : Agrupar con el responsable activo que publicó cada lote}')]
#[Description('Agrupa saldos compatibles sin modificar compras ni reservas existentes')]
class ConsolidateAvailableLots extends Command
{
    public function handle(AutomaticLotGrouping $grouping, LotAvailability $availability): int
    {
        $actorId = $this->option('actor');
        $lotId = $this->option('lot');
        $automatic = (bool) $this->option('automatic');
        if (($automatic && $actorId !== null) || (! $automatic && (! ctype_digit((string) $actorId) || (int) $actorId < 1)) || ($lotId !== null && (! ctype_digit((string) $lotId) || (int) $lotId < 1))) {
            $this->error('Indica --actor con el ID del responsable y, opcionalmente, --lot con un ID válido.');

            return self::FAILURE;
        }
        $actor = $automatic ? null : User::query()->find($actorId);
        if (! $automatic && (! $actor?->active || ! $actor->hasPermission('manage-lots'))) {
            $this->error('El usuario responsable no tiene acceso activo para gestionar lotes.');

            return self::FAILURE;
        }
        $ids = $availability->marketplace()->when($lotId !== null, fn ($query) => $query->whereKey($lotId))->limit(200)->pluck('lots.id');
        $seen = [];
        $groups = 0;
        foreach ($ids as $id) {
            $anchor = Lot::query()->findOrFail($id);
            if (in_array($anchor->code, $seen, true)) {
                continue;
            }
            $responsible = $automatic ? User::query()->find($anchor->created_by) : $actor;
            if (! $responsible?->active || ! $responsible->hasPermission('manage-lots')) {
                continue;
            }
            $result = $grouping->consolidate($responsible, $anchor, $automatic || (bool) $this->option('apply'));
            if ($result['lots'] === []) {
                continue;
            }
            $seen = [...$seen, ...$result['lots']];
            $groups++;
            $this->line(implode(' + ', $result['lots']).': '.$result['quantity'].' '.$anchor->baseUnit->code
                .($result['lot'] ? ' → '.$result['lot']->code : ' (propuesta; sin cambios)'));
        }
        $this->info($groups === 0 ? 'No hay saldos compatibles para agrupar ahora.' : 'Grupos '.($automatic || $this->option('apply') ? 'publicados' : 'propuestos').': '.$groups.'.');

        return self::SUCCESS;
    }
}
