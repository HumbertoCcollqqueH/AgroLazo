<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['kg', 'Kilogramo', 'mass'], ['g', 'Gramo', 'mass'], ['t', 'Tonelada', 'mass'],
                ['unidad', 'Unidad', 'count'], ['saco', 'Saco', 'package'],
            ] as [$code, $name, $dimension]) {
                Unit::query()->firstOrCreate(['code' => $code], compact('name', 'dimension'));
            }
            $baseUnit = Unit::query()->where('code', 'kg')->firstOrFail();
            foreach ([
                ['granos-andinos', 'Granos andinos', 'quinua', 'Quinua'],
                ['tuberculos', 'Tubérculos', 'papa', 'Papa'],
            ] as [$categorySlug, $categoryName, $slug, $name]) {
                $category = ProductCategory::query()->firstOrCreate(['slug' => $categorySlug], ['name' => $categoryName]);
                $product = Product::query()->firstOrCreate(['slug' => $slug], [
                    'name' => $name, 'category_id' => $category->id, 'base_unit_id' => $baseUnit->id,
                ]);
                ProductVariety::query()->firstOrCreate(['product_id' => $product->id, 'code' => 'unspecified'], ['name' => 'Sin especificar']);
                QualityGrade::query()->firstOrCreate(['product_id' => $product->id, 'code' => 'unspecified'], ['name' => 'Sin especificar']);
            }
            $country = Location::query()->firstOrCreate(['name' => 'Perú', 'type' => 'country', 'parent_id' => null]);
            Location::query()->firstOrCreate(['name' => 'Puno', 'type' => 'department', 'parent_id' => $country->id]);
        });
    }
}
