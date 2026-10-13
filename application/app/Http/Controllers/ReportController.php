<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Jobs\GenerateReportExport;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Commerce;
use App\Services\LotAvailability;
use App\Services\OperationsDashboard;
use App\Services\ReportDocument;
use App\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function dashboard(ReportFilterRequest $request, ReportService $reports, OperationsDashboard $operations): View
    {
        $actor = $request->user();
        $permissions = $actor->permissionCodes();
        $isOperator = $permissions->contains('view-operations');
        $isBuyer = ! $isOperator && $permissions->contains('view-buyer-area');
        $filters = $request->filters();
        $summary = $isOperator || $isBuyer ? $reports->snapshot($actor, $filters) : null;
        $operationsDashboard = $isOperator ? $operations->snapshot($actor, $filters) : null;

        $buyerLots = collect();
        $buyerOrders = collect();
        $buyerLotCount = 0;
        if ($isBuyer) {
            $query = app(LotAvailability::class)->marketplace();
            $buyerLotCount = (clone $query)->count();
            $buyerLots = $query->limit(6)->get();
            $buyerOrders = Order::query()
                ->whereHas('buyer', fn ($query) => $query->where('user_id', $actor->id))
                ->where('synthetic', $filters['data'] === 'synthetic')
                ->with(['items.baseUnit', 'items.allocations'])
                ->latest('confirmed_at')
                ->latest('id')
                ->limit(3)
                ->get();
        }

        return view('dashboard', ['reportSummary' => $summary, 'operationsDashboard' => $operationsDashboard, 'buyerLots' => $buyerLots, 'buyerLotCount' => $buyerLotCount, 'buyerOrders' => $buyerOrders]);
    }

    public function index(ReportFilterRequest $request, ReportService $reports): View
    {
        $type = $request->validate(['report' => ['nullable', Rule::in(array_keys(ReportService::TYPES))]])['report'] ?? 'sales';
        $snapshot = $reports->snapshot($request->user(), $request->filters(), $type);

        return view('reports.index', ['snapshot' => $snapshot, 'types' => $reports->types($request->user()), 'products' => Product::query()->orderBy('name')->get(['id', 'name']), 'locations' => Location::query()->orderBy('name')->get(['id', 'name']), 'exports' => ReportExport::query()->where('user_id', $request->user()->id)->latest('id')->limit(15)->get()]);
    }

    public function export(ReportFilterRequest $request, ReportService $reports, Commerce $commerce): RedirectResponse
    {
        $input = $request->validate(['key' => 'required|uuid', 'report' => ['required', Rule::in(array_keys(ReportService::TYPES))], 'format' => ['required', Rule::in(array_keys(ReportDocument::MIME))]]);
        $filters = $request->filters();
        $export = $commerce->transaction($request->user(), null, function (User $actor) use ($reports, $input, $filters): ReportExport {
            $reports->authorize($actor, $input['report']);
            $payload = hash('sha256', json_encode([$input['report'], $input['format'], $filters], JSON_THROW_ON_ERROR));
            $existing = ReportExport::query()->where('user_id', $actor->id)->where('key', $input['key'])->first();
            if ($existing) {
                Commerce::ensure(hash_equals($existing->payload_hash, $payload), 'key', 'La solicitud ya existe con otros filtros.');

                return $existing;
            }
            Commerce::ensure(ReportExport::query()->where('user_id', $actor->id)->where('status', 'pending')->where('expires_at', '>', now())->count() < 3, 'export', 'Ya hay tres exportaciones pendientes. Espera a que terminen.');
            $export = ReportExport::query()->create(['user_id' => $actor->id, 'key' => $input['key'], 'payload_hash' => $payload, 'report' => $input['report'], 'format' => $input['format'], 'filters' => $filters, 'status' => 'pending', 'expires_at' => now()->addDay()]);
            AuditLog::record($actor, 'report.requested', $export, ['report' => $export->report, 'format' => $export->format], 'private_export');

            return $export;
        });
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return to_route('reports.index', [...array_filter($filters, fn ($v) => $v !== null), 'report' => $input['report']])->with('status', 'Exportación solicitada. Actualiza esta página para descargarla cuando termine.');
    }

    public function download(Request $request, ReportExport $export, ReportService $reports): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        $reports->authorize($request->user(), $export->report);
        abort_unless($export->status === 'ready' && $export->expires_at->isFuture() && $export->path !== null && Storage::disk('local')->exists($export->path), 404);
        AuditLog::record($request->user(), 'report.downloaded', $export, ['report' => $export->report, 'format' => $export->format], 'private_export');

        return Storage::disk('local')->download($export->path, 'agrolazo-'.$export->report.'-'.$export->id.'.'.$export->format, ['Content-Type' => ReportDocument::MIME[$export->format], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
