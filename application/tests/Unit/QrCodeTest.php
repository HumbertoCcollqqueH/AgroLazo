<?php

namespace Tests\Unit;

use App\Services\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QrCodeTest extends TestCase
{
    /** Golden matrices generated independently by Nayuki Python encode_segments, version 5-L, byte mode, mask 0, boostecl=False. */
    public function test_encoding_matches_independent_reference_matrices_including_capacity_boundary(): void
    {
        $references = [
            'https://agroconecta.test/trace/0123456789abcdefghijklmnopqrstuv' => 'ecd569c219c5ba03c1f533285c7ae3189167b76a07a4645c6d24d6b83dec8951',
            'http://localhost:8080/trace/aBcDeFgHiJkLmNoPqRsTuVwXyZ012345' => 'd453f6dd4ea1f5e9648da892e4a3d11ff5ad4edd0242ffadd4ff377f5b0e1858',
            str_repeat('x', 106) => '28571cbdad17176449c375be5ff4484c96ab69a72384c1fbe30595e2a902a1f5',
            'A' => '0154b8028bd22c3b2737abf4c005eb3c99a9ee1d66f3401c8972eed3c4be36ea',
        ];
        foreach ($references as $text => $expectedHash) {
            $matrix = (new QrCode)->matrix($text);
            $this->assertCount(37, $matrix);
            $serialized = implode("\n", array_map(fn (array $row): string => implode('', array_map(fn (bool $dark): string => $dark ? '1' : '0', $row)), $matrix));
            $this->assertSame($expectedHash, hash('sha256', $serialized), $text);
        }
    }

    public function test_svg_has_a_four_module_quiet_zone_and_never_embeds_input_as_markup(): void
    {
        $svg = (new QrCode)->svg('<script>alert(1)</script>');
        $this->assertStringContainsString('viewBox="0 0 45 45"', $svg);
        $this->assertStringContainsString('M4,4h1v1h-1z', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('http://localhost', $svg);
    }

    public function test_oversize_payload_is_rejected_instead_of_generating_a_truncated_qr(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QrCode)->matrix(str_repeat('x', 107));
    }
}
