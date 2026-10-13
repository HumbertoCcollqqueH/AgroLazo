<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(CatalogSeeder::class);
        $this->call(ProductionCatalogSeeder::class);
        $this->call(PunoLaunchCatalogSeeder::class);
        $this->call(PaymentMethodSeeder::class);
    }
}
