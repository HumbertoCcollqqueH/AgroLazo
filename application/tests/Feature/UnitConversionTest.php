<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\UnitConversionService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('siQuantities')]
    public function test_si_equivalences_are_exact_and_round_half_up(string $quantity, string $unitCode, string $expected, string $factor): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $unit = Unit::query()->where('code', $unitCode)->firstOrFail();

        $result = app(UnitConversionService::class)->convert($quantity, $unit->id, $product->base_unit_id, $product->id);

        $this->assertSame($expected, $result['quantity']);
        $this->assertSame($factor, $result['factor']);
        $this->assertNull($result['conversion_id']);
    }

    public static function siQuantities(): array
    {
        return [
            'identity' => ['80', 'kg', '80.000', '1.000000000'],
            'grams' => ['1000', 'g', '1.000', '0.001000000'],
            'tonnes' => ['1.125', 't', '1125.000', '1000.000000000'],
            'round up' => ['1.5', 'g', '0.002', '0.001000000'],
            'round down' => ['1.4', 'g', '0.001', '0.001000000'],
        ];
    }

    public function test_package_conversion_requires_the_approved_product_context_and_dates(): void
    {
        $conversion = UnitConversion::factory()->create(['factor' => '50.125000000', 'valid_from' => '2026-09-01', 'valid_until' => '2026-09-30', 'verified_at' => '2026-08-31 12:00:00']);
        $result = app(UnitConversionService::class)->convert('4', $conversion->from_unit_id, $conversion->to_unit_id, $conversion->product_id, 'saco-prueba', '2026-09-30');

        $this->assertSame('200.500', $result['quantity']);
        $this->assertSame($conversion->id, $result['conversion_id']);
        $this->assertSame('Pesaje sintético de prueba', $result['source']);
    }

    #[DataProvider('unavailableConversions')]
    public function test_unknown_expired_or_disabled_equivalences_are_rejected(string $scenario): void
    {
        $conversion = UnitConversion::factory()->create(['valid_from' => '2026-09-01', 'valid_until' => '2026-09-30', 'verified_at' => '2026-08-31 12:00:00']);
        $context = 'saco-prueba';
        $date = '2026-09-15';
        $productId = $conversion->product_id;
        if ($scenario === 'context') {
            $context = 'otro-saco';
        } elseif ($scenario === 'expired') {
            $date = '2026-10-01';
        } elseif ($scenario === 'not-yet-valid') {
            $date = '2026-08-30';
        } elseif ($scenario === 'inactive') {
            $conversion->update(['active' => false]);
        } elseif ($scenario === 'other-product') {
            $productId = Product::factory()->create(['base_unit_id' => $conversion->to_unit_id])->id;
        }

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert('2', $conversion->from_unit_id, $conversion->to_unit_id, $productId, $context, $date);
    }

    public static function unavailableConversions(): array
    {
        return array_combine(['context', 'expired', 'not-yet-valid', 'inactive', 'other-product'], array_map(fn (string $scenario): array => [$scenario], ['context', 'expired', 'not-yet-valid', 'inactive', 'other-product']));
    }

    public function test_a_sack_without_a_factor_never_defaults_to_kilograms(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('slug', 'papa')->firstOrFail();
        $sack = Unit::query()->where('code', 'saco')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert('5', $sack->id, $product->base_unit_id, $product->id);
    }

    public function test_ambiguous_factors_fail_instead_of_picking_the_first_one(): void
    {
        $conversion = UnitConversion::factory()->create();
        UnitConversion::factory()->create([
            'product_id' => $conversion->product_id, 'from_unit_id' => $conversion->from_unit_id,
            'to_unit_id' => $conversion->to_unit_id, 'context' => $conversion->context, 'factor' => '60',
        ]);

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert('5', $conversion->from_unit_id, $conversion->to_unit_id, $conversion->product_id, $conversion->context);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_or_unrepresentable_quantities_are_rejected(string $quantity, string $unitCode): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $unit = Unit::query()->where('code', $unitCode)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert($quantity, $unit->id, $product->base_unit_id, $product->id);
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0', 'kg'], 'negative' => ['-1', 'kg'], 'scientific' => ['1e3', 'kg'],
            'excess precision' => ['1.0001', 'kg'], 'excess integer digits' => ['1000000000000000', 'kg'],
            'rounds to zero' => ['0.001', 'g'], 'output overflow' => ['999999999999999', 't'],
        ];
    }

    public function test_count_cannot_be_converted_to_mass_even_with_an_erroneous_stored_factor(): void
    {
        $from = Unit::factory()->create(['dimension' => 'count']);
        $conversion = UnitConversion::factory()->create(['from_unit_id' => $from->id]);

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert('2', $from->id, $conversion->to_unit_id, $conversion->product_id, $conversion->context);
    }

    public function test_deactivated_product_references_block_even_identity_conversion(): void
    {
        $product = Product::factory()->create();
        $product->category->update(['active' => false]);

        $this->expectException(ValidationException::class);
        app(UnitConversionService::class)->convert('2', $product->base_unit_id, $product->base_unit_id, $product->id);
    }

    public function test_verification_uses_the_local_calendar_date_at_the_utc_boundary(): void
    {
        $conversion = UnitConversion::factory()->create([
            'valid_from' => '2026-09-09', 'verified_at' => '2026-09-10 03:00:00',
        ]);

        $result = app(UnitConversionService::class)->convert('1', $conversion->from_unit_id, $conversion->to_unit_id, $conversion->product_id, $conversion->context, '2026-09-09');

        $this->assertSame('50.000', $result['quantity']);
    }

    public function test_coordinator_can_normalize_through_the_http_endpoint_without_creating_records(): void
    {
        $this->seed([CatalogSeeder::class, RolesAndPermissionsSeeder::class]);
        $coordinator = User::factory()->withRole('coordinator')->create();
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $unit = Unit::query()->where('code', 'g')->firstOrFail();

        $this->actingAs($coordinator)->postJson(route('catalog.normalize'), ['quantity' => '1000', 'product_id' => $product->id, 'from_unit_id' => $unit->id])
            ->assertExactJson(['quantity' => '1.000', 'factor' => '0.001000000', 'conversion_id' => null, 'source' => 'Equivalencia SI']);

        $this->assertDatabaseCount('unit_conversions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
