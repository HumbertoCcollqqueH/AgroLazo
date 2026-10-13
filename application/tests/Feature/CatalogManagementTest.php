<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariety;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admin_can_write_and_coordinator_can_read_catalogs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $coordinator = User::factory()->withRole('coordinator')->create();
        $buyer = User::factory()->withRole('buyer')->create();

        $this->assertTrue($admin->can('view-catalogs'));
        $this->assertTrue($admin->can('manage-catalogs'));
        $this->assertTrue($coordinator->can('view-catalogs'));
        $this->assertFalse($coordinator->can('manage-catalogs'));
        $this->assertFalse($buyer->can('view-catalogs'));
        $this->assertFalse($buyer->can('manage-catalogs'));

        $this->get(route('catalog.index', 'products'))->assertRedirectToRoute('login');
        $this->actingAs($buyer)->get(route('catalog.index', 'products'))->assertForbidden();
        $this->postJson(route('catalog.normalize'), [])->assertForbidden();
        $this->actingAs($coordinator)->get(route('catalog.index', 'products'))->assertSee('Catálogo de productos');
        $this->post(route('catalog.store', 'categories'), $this->categoryData())->assertForbidden();
        $this->assertDatabaseCount('product_categories', 0);
    }

    #[DataProvider('catalogKeys')]
    public function test_catalog_listing_and_create_forms_render_for_admin(string $catalog): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $admin = User::factory()->withRole('admin')->create();

        $this->actingAs($admin)->get(route('catalog.index', $catalog))->assertViewIs('catalog.index');
        $this->get(route('catalog.create', $catalog))->assertViewIs('catalog.form');
    }

    public static function catalogKeys(): array
    {
        return array_map(fn (string $key): array => [$key], ['categories', 'products', 'varieties', 'qualities', 'units', 'locations', 'conversions']);
    }

    public function test_admin_creates_a_generic_product_with_variety_and_quality_and_audit(): void
    {
        $admin = $this->admin();
        $unit = Unit::factory()->create();
        $category = ProductCategory::factory()->create();
        $this->actingAs($admin)->post(route('catalog.store', 'products'), [
            'name' => 'Haba', 'slug' => 'haba', 'category_id' => $category->id, 'base_unit_id' => $unit->id,
            'active' => '1', 'reason' => 'Producto adicional del piloto', 'id' => 999999,
        ])->assertRedirectToRoute('catalog.index', 'products');
        $product = Product::query()->where('slug', 'haba')->firstOrFail();

        $this->post(route('catalog.store', 'varieties'), [
            'name' => 'Variedad de prueba', 'code' => 'prueba', 'product_id' => $product->id,
            'active' => true, 'reason' => 'Catálogo de prueba',
        ])->assertRedirectToRoute('catalog.index', 'varieties');
        $this->post(route('catalog.store', 'qualities'), [
            'name' => 'Calidad de prueba', 'code' => 'prueba', 'product_id' => $product->id,
            'criteria' => 'Revisar integridad del grano', 'active' => true, 'reason' => 'Catálogo de prueba',
        ])->assertRedirectToRoute('catalog.index', 'qualities');

        $this->assertDatabaseHas('product_varieties', ['product_id' => $product->id, 'code' => 'prueba']);
        $this->assertDatabaseHas('quality_grades', ['product_id' => $product->id, 'criteria' => 'Revisar integridad del grano']);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'catalog.created']);
        $this->assertNotSame(999999, $product->id);
    }

    public function test_deactivation_preserves_references_and_blocks_new_variants(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $variety = ProductVariety::factory()->create(['product_id' => $product->id]);

        $this->actingAs($admin)->patch(route('catalog.update', ['products', $product->id]), [
            'name' => $product->name, 'active' => false, 'reason' => 'Retirado del catálogo',
        ])->assertRedirectToRoute('catalog.index', 'products');
        $this->assertModelExists($variety);
        $this->assertSame($product->id, $variety->fresh()->product_id);
        $this->post(route('catalog.store', 'varieties'), [
            'name' => 'Otra variedad', 'code' => 'otra', 'product_id' => $product->id, 'active' => true, 'reason' => 'Alta de prueba',
        ])->assertSessionHasErrors('product_id');
        $this->assertDatabaseCount('product_varieties', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_inactive_category_or_package_base_cannot_be_used_for_new_products(): void
    {
        $admin = $this->admin();
        $category = ProductCategory::factory()->inactive()->create();
        $unit = Unit::factory()->create();
        $data = ['name' => 'Maíz', 'slug' => 'maiz', 'category_id' => $category->id, 'base_unit_id' => $unit->id, 'active' => true, 'reason' => 'Alta de prueba'];

        $this->actingAs($admin)->post(route('catalog.store', 'products'), $data)->assertSessionHasErrors('category_id');
        $category->update(['active' => true]);
        $package = Unit::factory()->create(['dimension' => 'package']);
        $this->post(route('catalog.store', 'products'), [...$data, 'base_unit_id' => $package->id])->assertSessionHasErrors('base_unit_id');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_structural_fields_cannot_be_changed_through_http_or_model_updates(): void
    {
        $admin = $this->admin();
        $variety = ProductVariety::factory()->create();
        $other = Product::factory()->create();

        $this->actingAs($admin)->patch(route('catalog.update', ['varieties', $variety->id]), [
            'name' => 'Cambio', 'active' => true, 'product_id' => $other->id, 'reason' => 'Intento de reasignación',
        ])->assertSessionHasErrors('product_id');
        $this->assertNotSame($other->id, $variety->fresh()->product_id);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->expectException(ValidationException::class);
        $variety->update(['product_id' => $other->id]);
    }

    public function test_locations_enforce_hierarchy_and_preserve_parent_references(): void
    {
        $admin = $this->admin();
        $country = Location::factory()->create();
        $this->actingAs($admin)->post(route('catalog.store', 'locations'), [
            'name' => 'Distrito de prueba', 'type' => 'district', 'parent_id' => $country->id, 'active' => true, 'reason' => 'Alta geográfica de prueba',
        ])->assertSessionHasErrors('parent_id');

        $this->post(route('catalog.store', 'locations'), [
            'name' => 'Departamento de prueba', 'type' => 'department', 'parent_id' => $country->id,
            'active' => true, 'reason' => 'Alta geográfica de prueba',
        ])->assertRedirectToRoute('catalog.index', 'locations');
        $child = Location::query()->where('type', 'department')->firstOrFail();
        $this->assertNull($child->code);
        $this->assertTrue($child->isUsable());

        $this->patch(route('catalog.update', ['locations', $country->id]), [
            'name' => $country->name, 'active' => false, 'reason' => 'Retiro de prueba',
        ])->assertSessionHas('status');
        $this->assertModelExists($child);
        $this->assertFalse($child->fresh()->isUsable());
    }

    public function test_duplicate_locations_and_si_units_with_wrong_dimension_are_rejected(): void
    {
        $admin = $this->admin();
        Location::factory()->create(['name' => 'País de prueba']);

        $this->actingAs($admin)->post(route('catalog.store', 'locations'), [
            'name' => 'PAÍS DE PRUEBA', 'type' => 'country', 'active' => true, 'reason' => 'Duplicado de prueba',
        ])->assertSessionHasErrors('name');
        $this->post(route('catalog.store', 'units'), [
            'name' => 'Kilogramo incorrecto', 'code' => 'kg', 'dimension' => 'count', 'active' => true, 'reason' => 'Unidad de prueba',
        ])->assertSessionHasErrors('dimension');

        $this->assertDatabaseCount('units', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_renaming_an_inactive_location_to_a_duplicate_returns_validation_instead_of_database_error(): void
    {
        $admin = $this->admin();
        Location::factory()->create(['name' => 'País existente']);
        $inactive = Location::factory()->inactive()->create(['name' => 'País desactivado']);

        $this->actingAs($admin)->patch(route('catalog.update', ['locations', $inactive->id]), [
            'name' => 'País existente', 'active' => false, 'reason' => 'Corrección de nombre de prueba',
        ])->assertSessionHasErrors('name');

        $this->assertSame('País desactivado', $inactive->fresh()->name);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approved_conversion_records_actor_and_rejects_overlapping_windows_or_factor_changes(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $from = Unit::factory()->create(['dimension' => 'package']);
        $data = $this->conversionData($product, $from);

        $this->actingAs($admin)->post(route('catalog.store', 'conversions'), [...$data, 'verified_by' => 999999, 'verified_at' => '1990-01-01'])
            ->assertRedirectToRoute('catalog.index', 'conversions');
        $conversion = UnitConversion::query()->firstOrFail();
        $this->assertSame($admin->id, $conversion->verified_by);
        $this->assertSame('50.000000000', $conversion->factor);
        $this->post(route('catalog.store', 'conversions'), [...$data, 'valid_from' => '2026-09-30', 'valid_until' => '2026-10-30'])
            ->assertSessionHasErrors('valid_from');
        $this->patch(route('catalog.update', ['conversions', $conversion->id]), [
            'active' => true, 'factor' => '60', 'reason' => 'Intento de cambio de factor',
        ])->assertSessionHasErrors('factor');
        $this->assertDatabaseCount('unit_conversions', 1);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->patch(route('catalog.update', ['conversions', $conversion->id]), ['active' => false, 'reason' => 'Retiro de factor anterior'])->assertSessionHas('status');
        $this->post(route('catalog.store', 'conversions'), [...$data, 'factor' => '60'])->assertSessionHas('status');
        $this->assertDatabaseCount('unit_conversions', 2);
        $this->assertSame('50.000000000', $conversion->fresh()->factor);
        $this->patch(route('catalog.update', ['conversions', $conversion->id]), ['active' => true, 'reason' => 'Reactivar versión antigua'])
            ->assertSessionHasErrors('valid_from');
        $this->assertFalse($conversion->fresh()->active);
    }

    #[DataProvider('invalidConversionFields')]
    public function test_invalid_conversion_input_has_no_database_side_effects(array $changes, string $field): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $from = Unit::factory()->create(['dimension' => 'package']);

        $this->actingAs($admin)->post(route('catalog.store', 'conversions'), [...$this->conversionData($product, $from), ...$changes])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('unit_conversions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidConversionFields(): array
    {
        return [
            'zero factor' => [['factor' => '0'], 'factor'],
            'negative factor' => [['factor' => '-2'], 'factor'],
            'float input' => [['factor' => 1.5], 'factor'],
            'scientific notation' => [['factor' => '1e3'], 'factor'],
            'excess factor precision' => [['factor' => '1.1234567890'], 'factor'],
            'missing source' => [['source' => ''], 'source'],
            'missing context' => [['context' => ''], 'context'],
            'reversed dates' => [['valid_until' => '2026-08-01'], 'valid_until'],
            'missing reason' => [['reason' => ''], 'reason'],
        ];
    }

    public function test_incorrect_conversion_destination_and_si_override_are_rejected(): void
    {
        $admin = $this->admin();
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('slug', 'papa')->firstOrFail();
        $from = Unit::query()->where('code', 'g')->firstOrFail();
        $data = $this->conversionData($product, $from);

        $this->actingAs($admin)->post(route('catalog.store', 'conversions'), $data)->assertSessionHasErrors('factor');
        $this->post(route('catalog.store', 'conversions'), [...$data, 'to_unit_id' => Unit::query()->where('code', 't')->firstOrFail()->id])
            ->assertSessionHasErrors('to_unit_id');
        $this->assertDatabaseCount('unit_conversions', 0);
    }

    public function test_catalog_seed_preserves_custom_data_and_deactivation_on_repeat(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('slug', 'papa')->firstOrFail();
        $product->update(['name' => 'Papa de prueba local', 'active' => false]);

        $this->seed(CatalogSeeder::class);

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('units', 5);
        $this->assertDatabaseCount('unit_conversions', 0);
        $this->assertSame('Papa de prueba local', $product->fresh()->name);
        $this->assertFalse($product->fresh()->active);
    }

    public function test_user_content_is_escaped_search_is_literal_and_unknown_catalogs_are_not_resolved(): void
    {
        $admin = $this->admin();
        $name = '<script>alert("test")</script>';
        ProductCategory::factory()->create(['name' => $name]);

        $this->actingAs($admin)->get(route('catalog.index', 'categories'))->assertSee($name)->assertDontSee($name, false);
        $this->get(route('catalog.index', ['catalog' => 'categories', 'q' => '%']))->assertDontSee($name);
        $this->get(route('catalog.index', 'users'))->assertNotFound();
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->withRole('admin')->create();
    }

    private function categoryData(): array
    {
        return ['name' => 'Legumbres', 'slug' => 'legumbres', 'active' => true, 'reason' => 'Alta de catálogo'];
    }

    private function conversionData(Product $product, Unit $from): array
    {
        return [
            'product_id' => $product->id, 'from_unit_id' => $from->id, 'to_unit_id' => $product->base_unit_id,
            'factor' => '50', 'context' => 'saco-prueba', 'source' => 'Pesaje sintético de prueba',
            'valid_from' => '2026-09-01', 'valid_until' => '2026-09-30', 'active' => true, 'reason' => 'Aprobación sintética de prueba',
        ];
    }
}
