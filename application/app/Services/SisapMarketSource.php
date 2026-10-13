<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SisapMarketSource
{
    public const PORTAL = 'http://sistemas.midagri.gob.pe/sisap/portal/index1.php';

    public const QUERY = 'http://sistemas.midagri.gob.pe/sisap/portal2/mayorista/resumenes/filtrar';

    public const FORM = 'http://sistemas.midagri.gob.pe/sisap/portal2/mayorista/';

    public const VARIETIES = [
        'papa' => ['amarilla' => 'Amarilla', 'blanca' => 'Blanca', 'canchan' => 'Canchán', 'color' => 'Color',
            'huamantanga' => 'Huamantanga', 'huayro' => 'Huayro', 'negra-andina' => 'Negra Andina',
            'peruanita' => 'Peruanita', 'unica' => 'Única', 'yungay' => 'Yungay'],
        'quinua' => ['blanca' => 'Blanca'],
    ];

    private const MARKETS = [
        'papa' => ['id' => '15011501', 'product' => '0104', 'name' => 'GRAN MERCADO MAYORISTA DE LIMA'],
        'quinua' => ['id' => '15013704', 'product' => '0405', 'name' => 'MCDO PROD. SANTA ANITA'],
    ];

    public function refresh(): array
    {
        $prices = [];
        $failures = [];
        foreach (self::MARKETS as $product => $market) {
            try {
                $cookies = new CookieJar;
                $client = Http::connectTimeout(5)->timeout(12)->withOptions(['cookies' => $cookies, 'allow_redirects' => false]);
                $page = $client->get(self::PORTAL)->throw();
                if ($page->status() === 302 && in_array($page->header('Location'), ['../portal2/mayorista', self::FORM], true)) {
                    $page = $client->get(self::FORM)->throw();
                }
                if (! preg_match('/name=["\']?postID["\']?\s+value=["\']?([a-zA-Z0-9_-]+)/i', $page->body(), $match)) {
                    throw new RuntimeException('El formulario de consulta cambió.');
                }
                for ($days = 0; $days <= 3; $days++) {
                    $date = CarbonImmutable::now('America/Lima')->subDays($days);
                    $response = $client->asForm()->post(self::QUERY, [
                        'fecha' => $date->format('d/m/Y'), 'periodicidad' => 'dia', 'mercado' => $market['id'],
                        'productos' => [$market['product']], 'variables' => ['precio_prom'],
                        'ajax' => 'true', '__ajax_carga_final' => 'consulta', 'postID' => $match[1],
                    ])->throw();
                    if ($response->status() !== 200) {
                        throw new RuntimeException('La consulta redirigió fuera del servicio esperado.');
                    }
                    $observations = $this->parse($response->body(), $product, $date);
                    if ($observations !== []) {
                        $prices = [...$prices, ...$observations];
                        break;
                    }
                }
            } catch (Throwable) {
                $failures[$product] = 'No se pudo consultar SISAP para '.$product.'.';
            }
        }

        return ['prices' => $prices, 'failures' => $failures, 'fetched_at' => now()->toIso8601String()];
    }

    public function parse(string $html, string $product, CarbonImmutable $date): array
    {
        if (! isset(self::MARKETS[$product]) || $date->startOfDay()->isAfter(CarbonImmutable::now('America/Lima')->startOfDay())) {
            throw new RuntimeException('Producto o fecha no admitidos.');
        }
        if (mb_strlen($html) > 200000) {
            throw new RuntimeException('Respuesta demasiado grande.');
        }
        $dom = new DOMDocument;
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new DOMXPath($dom);
        $text = $this->normalize($dom->textContent);
        if (! str_contains($text, 'FECHA: '.$date->format('d/m/Y'))) {
            if (! $xpath->query('//table')->length && preg_match('/SIN (?:DATOS|RESULTADOS)|NO (?:HAY|EXISTEN RESULTADOS|SE ENCONTRO|SE ENCONTRARON)/', $text)) {
                return [];
            }
            throw new RuntimeException('La fecha del reporte no corresponde a la consulta.');
        }
        if (! $xpath->query('//tr[contains(@class,"contenido")]')->length) {
            return [];
        }
        if (! str_contains($text, self::MARKETS[$product]['name']) || ! str_contains($text, 'PRECIO PROMEDIO')
            || ! str_contains($text, 'S/. X KG.')) {
            throw new RuntimeException('Mercado, variable o unidad desconocidos.');
        }
        $prices = [];
        $knownLabels = [];
        foreach (self::VARIETIES[$product] as $code => $label) {
            $knownLabels[$this->normalize($product.' '.$label)] = $code;
        }
        foreach ($xpath->query('//tr[contains(@class,"contenido")]') as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 2 || $cells->length > 3) {
                throw new RuntimeException('Cambió la estructura de las observaciones.');
            }
            if ($cells->length === 3 && $this->normalize($cells->item(0)->textContent) !== strtoupper($product)) {
                throw new RuntimeException('La observación corresponde a otro producto.');
            }
            $label = $this->normalize($cells->item($cells->length - 2)->textContent);
            $value = trim($cells->item($cells->length - 1)->textContent);
            $code = $knownLabels[$label] ?? null;
            if (! $code || ! preg_match('/\A\d{1,5}(?:\.\d{1,6})?\z/', $value) || bccomp($value, '0', 6) <= 0) {
                continue;
            }
            $prices[$product.':'.$code] = [
                'unit_price' => bcadd($value, '0', 6), 'currency' => 'PEN', 'market' => self::MARKETS[$product]['name'],
                'observed_on' => $date->toDateString(), 'source' => self::PORTAL, 'provider' => 'sisap_midagri',
                'product_label' => ucfirst($product).' '.self::VARIETIES[$product][$code],
            ];
        }

        return $prices;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::upper(Str::ascii($value))));
    }
}
