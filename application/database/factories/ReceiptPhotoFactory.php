<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\ReceiptPhoto> */
class ReceiptPhotoFactory extends Factory
{
    public function definition(): array
    {
        return ['collection_receipt_id' => Models\CollectionReceipt::factory(), 'path' => 'receipt-photos/'.Str::uuid().'.png', 'mime' => 'image/png', 'size' => 68, 'sha256' => hash('sha256', (string) Str::uuid()), 'recorded_by' => Models\User::factory()];
    }
}
