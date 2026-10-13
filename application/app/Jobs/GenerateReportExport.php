<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Services\ReportDocument;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $exportId) {}

    public function handle(ReportService $reports, ReportDocument $documents): void
    {
        $path = null;
        $outer = DB::transactionLevel() === 0;
        try {
            DB::transaction(function () use ($reports, $documents, &$path, $outer): void {
                if ($outer) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                $export = ReportExport::query()->lockForUpdate()->find($this->exportId);
                if (! $export || $export->status !== 'pending') {
                    return;
                }
                if ($export->expires_at->isPast()) {
                    $export->update(['status' => 'expired']);

                    return;
                }
                $actor = $export->user()->firstOrFail();
                $reports->authorize($actor, $export->report);
                $snapshot = $reports->snapshot($actor, $export->filters, $export->report);
                $contents = $documents->render($snapshot, $export->format);
                $path = 'reports/'.Str::uuid().'.'.$export->format;
                if (! Storage::disk('local')->put($path, $contents)) {
                    throw new \RuntimeException('No se pudo guardar la exportación privada.');
                }
                $reports->authorize($actor->fresh(), $export->report);
                $export->update(['status' => 'ready', 'path' => $path, 'sha256' => hash('sha256', $contents), 'row_count' => count($snapshot['table']['rows']), 'generated_at' => now(), 'failure_code' => null]);
                AuditLog::record($actor, 'report.generated', $export, ['report' => $export->report, 'format' => $export->format, 'rows' => $export->row_count], 'private_export');
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            ReportExport::query()->whereKey($this->exportId)->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => 'generation_failed']);
        }
    }
}
