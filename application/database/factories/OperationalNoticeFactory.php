<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\OperationalNotice> */
class OperationalNoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['event_key' => 'synthetic:'.Str::uuid(), 'order_id' => Models\Order::factory(), 'user_id' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->buyer->user_id, 'body' => 'Aviso sintético de una orden de prueba', 'status' => 'pending', 'notification_uuid' => (string) Str::uuid()];
    }
}
