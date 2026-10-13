<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/** Text-only exports without macros, formulas or external resources. */
class ReportDocument
{
    public const MIME = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];

    public function render(array $snapshot, string $format): string
    {
        $meta = ['AgroLazo · '.$snapshot['table']['title'], 'Período: '.$snapshot['filters']['from'].' a '.$snapshot['filters']['to'].' · Perú', 'Datos comerciales: '.$snapshot['filters']['data'].' · Generado: '.$snapshot['generated_at'], $snapshot['table']['basis']];

        return match ($format) {
            'csv' => $this->csv($snapshot['table']),
            'xlsx' => $this->xlsx($snapshot['table'], $meta),
            'pdf' => $this->pdf($snapshot['table'], $meta),
            default => throw new RuntimeException('Formato no admitido.'),
        };
    }

    public static function safeCell(mixed $value): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        return preg_match('/\A[\s\p{Z}\x{FEFF}]*[=+\-@\t\r\n]/u', $text) ? "'".$text : $text;
    }

    private function csv(array $table): string
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ([$table['headers'], ...$table['rows']] as $row) {
            fputcsv($stream, array_map(self::safeCell(...), $row), ',', '"', '', "\r\n");
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    private function xml(mixed $text): string
    {
        return htmlspecialchars(preg_replace('/[^\P{C}\t\n\r]/u', '', (string) $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach ($rows as $i => $row) {
            $xml .= '<row r="'.($i + 1).'">';
            foreach ($row as $j => $value) {
                $column = '';
                for ($n = $j + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                    $column = chr(65 + ($n - 1) % 26).$column;
                }
                $xml .= '<c r="'.$column.($i + 1).'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function xlsx(array $table, array $meta): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agro-report-');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo preparar la exportación.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo construir el libro.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datos" sheetId="1" r:id="rId1"/><sheet name="Criterios" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet([$table['headers'], ...$table['rows']]));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet(array_map(fn ($v) => [$v], $meta)));
            $zip->close();
            $contents = file_get_contents($tmp);
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer el libro.');
            }

            return $contents;
        } finally {
            unlink($tmp);
        }
    }

    /** Paginated PDF 1.4; WinAnsi retains Spanish accents and transliterates other scripts. */
    private function pdf(array $table, array $meta): string
    {
        $lines = [];
        foreach ($meta as $line) {
            array_push($lines, ...explode("\n", wordwrap($this->latin($line), 100, "\n", true)));
        }
        $lines[] = 'Filas: '.count($table['rows']).'. Importes exactos; texto fuera de WinAnsi transliterado.';
        foreach ($table['rows'] as $i => $row) {
            $lines[] = '';
            $lines[] = 'REGISTRO '.($i + 1);
            foreach ($table['headers'] as $j => $header) {
                array_push($lines, ...explode("\n", wordwrap($this->latin($header.': '.($row[$j] ?? '')), 100, "\n", true)));
            }
        }
        if ($table['rows'] === []) {
            $lines[] = 'Sin registros para los filtros seleccionados.';
        }
        $pages = array_chunk($lines, 43);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $i => $page) {
            $id = 4 + $i * 2;
            $kids[] = "$id 0 R";
            $content = "BT /F1 9 Tf 12 TL 36 550 Td\n";
            $content .= '('.$this->escape($this->latin('AgroLazo · '.$table['title'].' · Página '.($i + 1).' / '.count($pages))).") Tj T*\n";
            foreach ($page as $line) {
                $content .= '('.$this->escape($line).") Tj T*\n";
            }
            $content .= 'ET';
            $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($id + 1).' 0 R >>';
            $objects[$id + 1] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($objects as $id => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    private function latin(string $value): string
    {
        return iconv('UTF-8', 'Windows-1252//TRANSLIT', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '') ?: '';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
