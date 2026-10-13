<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Services\ProducerIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['provider' => 'fake', 'account' => 'local-simulator', 'channel' => 'whatsapp', 'phone' => '+51900000003', 'phone_lookup' => app(ProducerIdentity::class)->lookup('phone', '+51900000003'), 'state' => 'NEW', 'context' => [], 'consent' => 'pending'];
    }
}
