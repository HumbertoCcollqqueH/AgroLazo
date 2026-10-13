<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionCatalogSeeder extends Seeder
{
    public const CROPS = [
        'granos-andinos' => ['Granos andinos', ['canihua' => 'Cañihua', 'kiwicha' => 'Kiwicha']],
        'cereales' => ['Cereales', ['cebada-grano' => 'Cebada grano', 'avena-grano' => 'Avena grano', 'trigo' => 'Trigo',
            'maiz-amilaceo' => 'Maíz amiláceo', 'maiz-amarillo-duro' => 'Maíz amarillo duro', 'maiz-choclo' => 'Maíz choclo', 'arroz-cascara' => 'Arroz cáscara']],
        'menestras' => ['Menestras', ['haba-grano-seco' => 'Haba grano seco', 'haba-grano-verde' => 'Haba grano verde',
            'tarwi-grano-seco' => 'Tarwi grano seco', 'arveja-grano-seco' => 'Arveja grano seco', 'arveja-grano-verde' => 'Arveja grano verde', 'frijol-grano-seco' => 'Frijol grano seco']],
        'tuberculos' => ['Tubérculos', ['oca' => 'Oca', 'olluco' => 'Olluco', 'mashua' => 'Mashua', 'camote' => 'Camote', 'yuca' => 'Yuca', 'maca' => 'Maca']],
        'hortalizas' => ['Hortalizas', ['zanahoria' => 'Zanahoria', 'cebolla' => 'Cebolla', 'ajo' => 'Ajo', 'zapallo' => 'Zapallo', 'tomate' => 'Tomate']],
        'frutas' => ['Frutas', ['palta' => 'Palta', 'platano' => 'Plátano', 'pina' => 'Piña']],
        'otros-cultivos' => ['Otros cultivos', ['cafe-pergamino' => 'Café pergamino', 'cacao-grano' => 'Cacao en grano']],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $kg = Unit::query()->where('code', 'kg')->firstOrFail();
            foreach (self::CROPS as $categorySlug => [$categoryName, $crops]) {
                $category = ProductCategory::query()->firstOrCreate(['slug' => $categorySlug], ['name' => $categoryName]);
                foreach ($crops as $slug => $name) {
                    $product = Product::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'category_id' => $category->id, 'base_unit_id' => $kg->id]);
                    if ($product->wasRecentlyCreated) {
                        AuditLog::record(null, 'catalog.crop_added', $product, ['source' => 'SENAMHI: boletín agroclimático de Puno; MIDAGRI: El Agro en Cifras'], 'Ampliación del catálogo de producciones agrícolas.');
                    }
                    ProductVariety::query()->firstOrCreate(['product_id' => $product->id, 'code' => 'unspecified'], ['name' => 'Sin especificar']);
                    QualityGrade::query()->firstOrCreate(['product_id' => $product->id, 'code' => 'unspecified'], ['name' => 'Sin especificar']);
                }
            }
        });
    }
}
