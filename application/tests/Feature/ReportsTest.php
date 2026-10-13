<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\ProducerConsent;
use App\Models\ProducerLocation;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\ReportDocument;
use App\Services\ReportService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommerceScenario;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64), 'commerce.live_enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
    }

    private function filters(array $extra = []): array
    {
        return ['from' => now('America/Lima')->subDay()->toDateString(), 'to' => now('America/Lima')->addDay()->toDateString(), 'data' => 'synthetic', 'product_id' => null, 'location_id' => null, ...$extra];
    }

    public function test_sales_dashboard_reconciles_exact_cents_and_never_counts_another_buyers_orders(): void
    {
        $scenario = new CommerceScenario('25.125');
        DB::table('units')->where('id', $scenario->order->items->sole()->base_unit_id)->update(['code' => 'kg']);
        $other = new CommerceScenario('10.000');
        $scenario->confirm($scenario->payment('100.00'));
        $snapshot = app(ReportService::class)->snapshot($scenario->buyer, $this->filters());
        $this->assertSame(1, $snapshot['metrics']['orders']);
        $this->assertSame('25.125', $snapshot['metrics']['quantity_kg']);
        $this->assertSame(12563, $snapshot['metrics']['gmv_minor']);
        $this->assertSame('5.000000', $snapshot['metrics']['average_kg']);
        $this->assertSame(12814, $snapshot['metrics']['total_minor']);
        $this->assertSame(2814, $snapshot['metrics']['buyer_due_minor']);
        $this->assertCount(1, $snapshot['table']['rows']);
        $this->assertSame([], $snapshot['inventory']);
        $this->assertSame([], $snapshot['map']);
        $this->actingAs($scenario->buyer)->get('/reports')->assertOk()->assertSee($scenario->order->code)->assertDontSee('Neto productor calculado')->assertDontSee('Mapa de coordenadas');
        $this->actingAs($scenario->buyer)->get('/dashboard')->assertOk()
            ->assertSee('Tus compras, en cifras')
            ->assertSee('Tus compras recientes')
            ->assertSee($scenario->order->code)
            ->assertSee('Coordinación confirmará el lugar y horario de acopio.')
            ->assertDontSee($other->order->code)
            ->assertDontSee($other->buyer->email);
    }

    public function test_buyer_dashboard_keeps_older_orders_visible_and_separates_real_from_test_purchases(): void
    {
        $scenario = new CommerceScenario('20.000');
        $real = new CommerceScenario('10.000');
        DB::table('orders')->where('id', $scenario->order->id)->update(['confirmed_at' => now()->subMonth()->toDateTimeString()]);
        DB::table('orders')->where('id', $real->order->id)->update(['buyer_id' => $scenario->order->buyer_id, 'synthetic' => false]);

        $this->actingAs($scenario->buyer)->get('/dashboard?data=synthetic')->assertOk()
            ->assertSee($scenario->order->code)->assertDontSee($real->order->code)
            ->assertSee('Últimas órdenes de prueba, de cualquier fecha.');
        $this->get('/dashboard?data=real')->assertOk()
            ->assertSee($real->order->code)->assertDontSee($scenario->order->code)
            ->assertSee('Últimas órdenes reales, de cualquier fecha.');
    }

    public function test_confirmed_reduction_recomputes_gmv_without_counting_original_quantity(): void
    {
        $s = new CommerceScenario('25.125');
        DB::table('units')->where('id', $s->order->items->sole()->base_unit_id)->update(['code' => 'kg']);
        $service = app(CheckoutService::class);
        $adjustment = $service->proposeReduction($s->staff, $s->order, CommerceScenario::key(['version' => $s->order->version, 'allocation_id' => $s->order->items->sole()->allocations->sole()->id, 'quantity' => '5.125', 'retire' => false, 'reason' => 'Reducción acordada para prueba del reporte']));
        $service->acceptReduction($s->buyer, $adjustment, CommerceScenario::key(['accepted' => '1']));
        $result = app(ReportService::class)->snapshot($s->admin, $this->filters());
        $this->assertSame('20.000', $result['metrics']['quantity_kg']);
        $this->assertSame(10000, $result['metrics']['gmv_minor']);
        $this->assertSame($s->order->fresh()->total_minor, $result['metrics']['total_minor']);
    }

    public function test_latest_settlement_is_counted_once_and_receipts_remain_separate(): void
    {
        $s = new CommerceScenario;
        $event = $s->schedule();
        $s->receive($event, '20.000');
        $s->settlement();
        $s->receive($event, '40.000');
        $pending = app(ReportService::class)->snapshot($s->admin, $this->filters(), 'settlements');
        $this->assertSame(1, $pending['metrics']['receipts_unsettled']);
        $latest = $s->settlement();
        $report = app(ReportService::class)->snapshot($s->admin, $this->filters(), 'settlements');
        $this->assertCount(1, $report['table']['rows']);
        $this->assertSame($latest->net_minor, $report['metrics']['producer_net_minor']);
        $this->assertSame('60.000', $report['sales']->sole()['accepted']);
        $this->assertSame(0, $report['metrics']['receipts_unsettled']);
    }

    public function test_date_filters_use_peru_day_boundaries_and_synthetic_data_is_separate(): void
    {
        $s = new CommerceScenario;
        DB::table('orders')->where('id', $s->order->id)->update(['confirmed_at' => '2026-09-02 04:59:59+00']);
        $report = app(ReportService::class)->snapshot($s->buyer, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-01']));
        $this->assertSame(1, $report['metrics']['orders']);
        $empty = app(ReportService::class)->snapshot($s->buyer, $this->filters(['from' => '2026-09-02', 'to' => '2026-09-02']));
        $this->assertSame(0, $empty['metrics']['orders']);
        $real = app(ReportService::class)->snapshot($s->buyer, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-01', 'data' => 'real']));
        $this->assertSame(0, $real['metrics']['orders']);
    }

    public function test_report_authorization_and_filter_validation_are_enforced(): void
    {
        $s = new CommerceScenario;
        $this->get('/reports')->assertRedirect('/login');
        foreach (['producers', 'offers', 'lots', 'settlements', 'prices', 'audit'] as $report) {
            $this->actingAs($s->buyer)->get('/reports?report='.$report)->assertForbidden();
        }
        $this->actingAs($s->staff)->get('/reports?report=audit')->assertForbidden();
        $this->actingAs($s->admin)->getJson('/reports?from=2020-01-01&to=2026-01-01')->assertUnprocessable();
        $this->actingAs($s->admin)->getJson('/reports?product_id=1%20OR%201=1')->assertUnprocessable();
    }

    public function test_map_requires_latest_consent_and_never_exports_coordinates(): void
    {
        $s = new CommerceScenario;
        $producer = $s->order->items->sole()->allocations->sole()->portion->offer->producer;
        ProducerLocation::factory()->create(['producer_id' => $producer->id]);
        $reports = app(ReportService::class);
        $this->assertSame([], $reports->snapshot($s->admin, $this->filters())['map']);
        ProducerConsent::factory()->create(['producer_id' => $producer->id, 'purpose' => 'location_planning', 'recorded_at' => now()->subMinute()]);
        $snapshot = $reports->snapshot($s->admin, $this->filters());
        $this->assertNotEmpty($snapshot['map']);
        $this->assertStringNotContainsString('-15.840000', app(ReportDocument::class)->render($snapshot, 'csv'));
        ProducerConsent::factory()->declined()->create(['producer_id' => $producer->id, 'purpose' => 'location_planning']);
        $this->assertSame([], $reports->snapshot($s->admin, $this->filters())['map']);
    }

    public function test_each_operator_report_renders_without_private_identity_fields(): void
    {
        $s = new CommerceScenario;
        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->actingAs($s->admin)->get('/reports?report='.$type)->assertOk();
        }
        $snapshot = app(ReportService::class)->snapshot($s->admin, $this->filters(), 'producers');
        $this->assertNotContains('DNI', $snapshot['table']['headers']);
        $this->assertNotContains('Teléfono', $snapshot['table']['headers']);
    }

    public function test_export_requests_are_idempotent_and_private(): void
    {
        $s = new CommerceScenario;
        $data = CommerceScenario::key([...$this->filters(), 'report' => 'sales', 'format' => 'csv']);
        $this->actingAs($s->buyer)->post('/reports/exports', $data)->assertRedirect();
        $this->actingAs($s->buyer)->post('/reports/exports', $data)->assertRedirect();
        $export = ReportExport::query()->sole();
        $this->assertSame($s->buyer->id, $export->user_id);
        $this->assertStringNotContainsString('synthetic', DB::table('report_exports')->value('filters'));
        $this->actingAs($s->buyer)->postJson('/reports/exports', [...$data, 'format' => 'pdf'])->assertUnprocessable();
        $this->actingAs($s->admin)->get('/reports/exports/'.$export->id)->assertForbidden();
        Queue::assertPushed(GenerateReportExport::class);
    }

    public function test_job_runs_once_download_rechecks_role_and_expired_files_are_denied(): void
    {
        Storage::fake('local');
        $s = new CommerceScenario;
        $export = ReportExport::factory()->create(['user_id' => $s->buyer->id, 'filters' => $this->filters()]);
        $job = new GenerateReportExport($export->id);
        $job->handle(app(ReportService::class), app(ReportDocument::class));
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $original = $export->path;
        $job->handle(app(ReportService::class), app(ReportDocument::class));
        $this->assertSame($original, $export->fresh()->path);
        $this->actingAs($s->buyer)->get('/reports/exports/'.$export->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $export->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($s->buyer)->get('/reports/exports/'.$export->id)->assertNotFound();
    }

    public function test_permission_revoked_before_generation_creates_no_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->withRole('admin')->create();
        $export = ReportExport::factory()->create(['user_id' => $user->id, 'report' => 'audit']);
        $user->roles()->detach();
        (new GenerateReportExport($export->id))->handle(app(ReportService::class), app(ReportDocument::class));
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_csv_injection_and_xml_formulas_are_not_executable(): void
    {
        foreach (['=HYPERLINK("https://example.test")', '  +1+1', "\t@SUM(A1)", '-1+2', "\u{FEFF}=1"] as $value) {
            $this->assertStringStartsWith("'", ReportDocument::safeCell($value));
        }
        $this->assertSame('Quinua 25.125', ReportDocument::safeCell('Quinua 25.125'));
        $s = new CommerceScenario;
        $snapshot = app(ReportService::class)->snapshot($s->buyer, $this->filters());
        $snapshot['table']['rows'][0][0] = '=1+1 <script>';
        $document = app(ReportDocument::class);
        $this->assertStringContainsString("'=1+1", $document->render($snapshot, 'csv'));
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        try {
            file_put_contents($tmp, $document->render($snapshot, 'xlsx'));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($tmp));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('t="inlineStr"', $xml);
            $this->assertStringContainsString('=1+1 &lt;script&gt;', $xml);
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertNotFalse(simplexml_load_string($xml));
            $zip->close();
        } finally {
            unlink($tmp);
        }
    }

    public function test_pdf_is_paginated_and_preserves_every_record(): void
    {
        $snapshot = ['filters' => $this->filters(), 'generated_at' => '2026-09-18', 'table' => ['title' => 'Ventas', 'basis' => 'Prueba sintética', 'headers' => ['Código', 'Texto'], 'rows' => array_map(fn ($i) => ['O-'.$i, 'Papa (Perú) \\ '.str_repeat('texto largo ', 35)], range(1, 75))]];
        $pdf = app(ReportDocument::class)->render($snapshot, 'pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('REGISTRO 75', $pdf);
        $this->assertStringContainsString('startxref', $pdf);
        $this->assertGreaterThan(2, substr_count($pdf, '/Type /Page '));
    }

    public function test_expiration_prunes_only_expired_files_and_keeps_export_metadata(): void
    {
        Storage::fake('local');
        $expired = ReportExport::factory()->create(['status' => 'ready', 'path' => 'reports/11111111-1111-4111-8111-111111111111.csv', 'expires_at' => now()->subMinute()]);
        $active = ReportExport::factory()->create(['status' => 'ready', 'path' => 'reports/22222222-2222-4222-8222-222222222222.pdf', 'expires_at' => now()->addHour()]);
        Storage::disk('local')->put($expired->path, 'sensitive expired export');
        Storage::disk('local')->put($active->path, 'current export');
        $path = $expired->path;
        $this->artisan('reports:prune')->assertSuccessful();
        $this->artisan('reports:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($active->path);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNull($expired->fresh()->path);
        $this->assertSame('ready', $active->fresh()->status);
        $this->assertDatabaseCount('report_exports', 2);
    }
}
