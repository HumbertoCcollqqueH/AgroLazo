<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Location;
use App\Models\Offer;
use App\Models\Producer;
use App\Models\ProducerLocation;
use App\Models\Product;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProducerMultipleCropsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Producer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class, ProductionCatalogSeeder::class]);
        $this->producer = Producer::factory()->verified()->create();
        $this->producer->products()->attach(Product::query()->where('slug', 'quinua')->value('id'));
        ProducerLocation::factory()->create(['producer_id' => $this->producer->id, 'location_id' => Location::query()->where('name', 'Puno')->value('id')]);
        Http::preventStrayRequests();
        $this->say('HOLA');
        $this->say('ACEPTO');
    }

    private function say(string $body): Conversation
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(), $this->producer->phone, $body, CarbonImmutable::now());
        app(ConversationEngine::class)->process($message->conversation_id);

        return $message->conversation->fresh();
    }

    public function test_two_registered_crops_are_presented_even_after_days_and_previous_quinoa_offer(): void
    {
        $papa = Product::query()->where('slug', 'papa')->firstOrFail();
        $this->producer->products()->attach($papa->id);
        Offer::factory()->create(['producer_id' => $this->producer->id, 'product_id' => Product::query()->where('slug', 'quinua')->value('id')]);
        $this->travel(4)->days();
        $this->say('HOLA');
        $c = $this->say('1');
        $this->assertSame('OFFER_PRODUCT', $c->state);
        $this->assertEqualsCanonicalizing($this->producer->products()->pluck('products.id')->all(), array_values($c->context['choices']));
        $c = $this->say((string) array_search($papa->id, $c->context['choices'], true));
        $this->assertSame($papa->id, $c->context['draft']['product_id']);
        $this->assertSame('OFFER_QUANTITY', $c->state);
        Http::assertNothingSent();
    }

    public function test_other_crop_is_added_only_when_producer_confirms_and_retains_existing_crops(): void
    {
        $c = $this->say('VENDER');
        $this->assertSame('OFFER_QUANTITY', $c->state);
        $c = $this->say('OTRO');
        $crop = Product::query()->where('slug', 'canihua')->firstOrFail();
        $this->assertSame('OFFER_PRODUCT', $c->state);
        $this->assertContains($crop->id, $c->context['choices']);
        $this->say((string) array_search($crop->id, $c->context['choices'], true));
        $this->say('25');
        $this->say('1');
        $this->say('2');
        $this->assertSame(1, $this->producer->products()->count());
        $this->say('CONFIRMAR');
        $this->assertSame(2, $this->producer->products()->count());
        $this->assertDatabaseHas('offers', ['producer_id' => $this->producer->id, 'product_id' => $crop->id, 'quantity' => '25.000', 'status' => 'pending_verification']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'producer.crop_declared']);
        $c = $this->say('VENDER');
        $this->assertSame('OFFER_PRODUCT', $c->state);
        $this->assertContains($crop->id, $c->context['choices']);
    }

    public function test_cancelling_new_crop_does_not_modify_registered_productions(): void
    {
        $c = $this->say('OTRO');
        $papa = Product::query()->where('slug', 'papa')->firstOrFail();
        $this->say((string) array_search($papa->id, $c->context['choices'], true));
        $this->say('CANCELAR');
        $this->assertSame(1, $this->producer->products()->count());
        $this->assertDatabaseCount('offers', 0);
    }
}
