<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfferPhoto> */
class OfferPhotoFactory extends Factory
{
    public function definition(): array
    {
        return ['offer_id' => Offer::factory(), 'uploaded_by' => User::factory(), 'path' => 'offers/testing/photo.png', 'mime' => 'image/png', 'size' => 68, 'caption' => 'Evidencia sintética de prueba'];
    }
}
