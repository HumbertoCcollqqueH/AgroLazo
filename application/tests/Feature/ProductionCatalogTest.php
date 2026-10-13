<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductionCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_regional_crops_are_usable_and_repeated_import_does_not_duplicate_or_reactivate(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(ProductionCatalogSeeder::class);
        foreach (['canihua', 'tarwi-grano-seco', 'haba-grano-verde', 'oca', 'olluco', 'cebada-grano', 'pina'] as $slug) {
            $crop = Product::query()->where('slug', $slug)->firstOrFail();
            $this->assertTrue($crop->isUsable());
            $this->assertSame('kg', $crop->baseUnit->code);
            $this->assertSame(1, $crop->varieties()->count());
            $this->assertSame(1, $crop->qualityGrades()->count());
        }
        $counts = [Product::count(), ProductVariety::count(), QualityGrade::count()];
        Product::query()->where('slug', 'oca')->firstOrFail()->update(['active' => false]);
        $this->seed(ProductionCatalogSeeder::class);
        $this->assertSame($counts, [Product::count(), ProductVariety::count(), QualityGrade::count()]);
        $this->assertFalse(Product::query()->where('slug', 'oca')->firstOrFail()->active);
    }
}
