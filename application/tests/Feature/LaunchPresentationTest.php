<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Database\Seeders\PunoLaunchCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LaunchPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_launch_catalog_hides_other_crops_without_deleting_history(): void
    {
        $this->seed([CatalogSeeder::class, ProductionCatalogSeeder::class]);
        $count = Product::count();
        $this->seed(PunoLaunchCatalogSeeder::class);
        $this->seed(PunoLaunchCatalogSeeder::class);
        $this->assertSame($count, Product::count());
        $this->assertEqualsCanonicalizing(PunoLaunchCatalogSeeder::PRODUCTS, Product::where('active', true)->pluck('slug')->all());
        config(['commerce.show_test_labels' => false]);
        $this->get('/')->assertOk()->assertDontSee('Estás explorando el entorno de prueba')->assertSee('ac-main--market')->assertSee('Cañihua')->assertDontSee('Piña');
    }
}
