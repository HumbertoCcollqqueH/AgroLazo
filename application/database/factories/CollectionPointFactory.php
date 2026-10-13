<?php

namespace Database\Factories;

use App\Models\CollectionPoint;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollectionPoint> */
class CollectionPointFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Acopio sintético '.fake()->uuid(), 'description' => 'Punto exclusivo para pruebas automatizadas', 'address' => 'Dirección sintética privada de prueba', 'location_id' => Location::factory(), 'latitude' => null, 'longitude' => null, 'capacity' => '1000.000', 'base_unit_id' => Unit::factory(), 'opens_at' => '00:00', 'closes_at' => '23:59', 'active' => true, 'recorded_by' => User::factory()];
    }
}
