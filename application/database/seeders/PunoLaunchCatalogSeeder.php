<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PunoLaunchCatalogSeeder extends Seeder
{
    public const PRODUCTS = ['papa', 'quinua', 'canihua', 'oca', 'cebada-grano', 'trigo', 'avena-grano'];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (Product::query()->get() as $product) {
                $active = in_array($product->slug, self::PRODUCTS, true);
                if ($product->active !== $active) {
                    $product->update(['active' => $active]);
                    AuditLog::record(null, 'catalog.launch_scope_updated', $product, ['active' => $active], 'Catálogo inicial de Puno solicitado por administración; historial conservado.');
                }
            }
        });
    }
}
