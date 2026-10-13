<?php

namespace Tests\Feature;

use App\Services\SisapMarketSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SisapMarketSourceTest extends TestCase
{
    private function report(string $product, string $date = '29/09/2026'): string
    {
        $label = $product === 'papa' ? 'Papa' : 'Quinua';
        $market = $product === 'papa' ? 'GRAN MERCADO MAYORISTA DE LIMA' : 'MCDO PROD. SANTA ANITA';

        return '<h1>Lima Metropolitana: Precio Promedio según mercado y producto.</h1><h1>Fecha: '.$date.'</h1><table>'
            .'<tr class=encabezado><td>Producto</td><td>Variedad</td><td>Precio Promedio (S/. x Kg., S/. x Lt. o S/. x Unid.)</td></tr>'
            .'<tr class=contenido><td>'.$label.'</td><td>'.$label.' Blanca</td><td>9.53</td></tr></table>'
            .'<div>Fuente: '.$market.'</div>';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'America/Lima'));
        Http::preventStrayRequests();
    }

    public function test_official_rows_keep_product_variety_market_date_and_per_kg_price(): void
    {
        $prices = app(SisapMarketSource::class)->parse($this->report('quinua'), 'quinua', CarbonImmutable::parse('2026-09-29', 'America/Lima'));
        $this->assertSame('9.530000', $prices['quinua:blanca']['unit_price']);
        $this->assertSame('2026-09-29', $prices['quinua:blanca']['observed_on']);
        $this->assertSame('MCDO PROD. SANTA ANITA', $prices['quinua:blanca']['market']);
    }

    public function test_wrong_market_date_product_unit_and_future_dates_are_rejected(): void
    {
        $report = $this->report('quinua');
        foreach ([
            str_replace('29/09/2026', '28/09/2026', $report),
            str_replace('MCDO PROD. SANTA ANITA', 'MERCADO DESCONOCIDO', $report),
            str_replace('<td>Quinua</td>', '<td>Papa</td>', $report),
            str_replace('S/. x Kg.', 'Dólares por saco', $report),
        ] as $html) {
            try {
                app(SisapMarketSource::class)->parse($html, 'quinua', CarbonImmutable::parse('2026-09-29', 'America/Lima'));
                $this->fail('La clasificación o procedencia no corresponde.');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(\RuntimeException::class);
        app(SisapMarketSource::class)->parse($this->report('quinua', '01/10/2026'), 'quinua', CarbonImmutable::parse('2026-10-01', 'America/Lima'));
    }

    public function test_empty_today_falls_back_to_yesterday_without_claiming_todays_price(): void
    {
        Http::fake(function ($request) {
            if ($request->url() === SisapMarketSource::PORTAL) {
                return Http::response('', 302, ['Location' => '../portal2/mayorista']);
            }
            if ($request->url() === SisapMarketSource::FORM) {
                return Http::response('<input name=postID value=public-token>');
            }

            return Http::response($request['fecha'] === '30/09/2026'
                ? '<p class=mensajeDeError>No existen resultados para los criterios elegidos.</p>'
                : $this->report($request['mercado'] === '15011501' ? 'papa' : 'quinua'));
        });
        $result = app(SisapMarketSource::class)->refresh();
        $this->assertSame([], $result['failures']);
        $this->assertCount(2, $result['prices']);
        $this->assertSame('2026-09-29', $result['prices']['quinua:blanca']['observed_on']);
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && $request['fecha'] === '28/09/2026');
    }

    public function test_unknown_variety_and_zero_price_are_not_used(): void
    {
        $date = CarbonImmutable::parse('2026-09-29', 'America/Lima');
        $this->assertSame([], app(SisapMarketSource::class)->parse(str_replace('Quinua Blanca', 'Quinua Mezclada', $this->report('quinua')), 'quinua', $date));
        $this->assertSame([], app(SisapMarketSource::class)->parse(str_replace('9.53', '0.00', $this->report('quinua')), 'quinua', $date));
    }

    public function test_unexpected_redirect_never_sends_queries_to_another_host(): void
    {
        Http::fake([SisapMarketSource::PORTAL => Http::response('', 302, ['Location' => 'https://untrusted.example/data'])]);
        $result = app(SisapMarketSource::class)->refresh();
        $this->assertSame([], $result['prices']);
        $this->assertCount(2, $result['failures']);
        Http::assertSentCount(2);
    }
}
