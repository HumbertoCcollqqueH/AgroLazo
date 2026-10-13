<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneReportExports extends Command
{
    protected $signature = 'reports:prune';

    protected $description = 'Elimina archivos de reportes vencidos conservando la constancia de exportación.';

    public function handle(): int
    {
        $removed = 0;
        ReportExport::query()->where('expires_at', '<=', now())->where('status', '!=', 'expired')->select('id')->chunkById(100, function ($exports) use (&$removed): void {
            foreach ($exports as $candidate) {
                DB::transaction(function () use ($candidate, &$removed): void {
                    $export = ReportExport::query()->lockForUpdate()->find($candidate->id);
                    if (! $export || $export->expires_at->isFuture() || $export->status === 'expired') {
                        return;
                    }
                    if ($export->path !== null) {
                        if (! preg_match('/\Areports\/[a-f0-9-]{36}\.(?:csv|xlsx|pdf)\z/D', $export->path)) {
                            throw new \RuntimeException('Ruta de reporte inesperada; requiere revisión.');
                        }
                        if (Storage::disk('local')->exists($export->path) && ! Storage::disk('local')->delete($export->path)) {
                            throw new \RuntimeException('No se pudo eliminar el reporte vencido.');
                        }
                    }
                    $export->update(['status' => 'expired', 'path' => null]);
                    $removed++;
                });
            }
        });
        $this->info('Reportes vencidos retirados: '.$removed);

        return self::SUCCESS;
    }
}
