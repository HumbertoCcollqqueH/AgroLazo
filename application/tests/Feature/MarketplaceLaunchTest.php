<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Conversation;
use App\Models\Lot;
use App\Models\OperationalNotice;
use App\Models\PricingPolicy;
use App\Models\Producer;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\LotAvailability;
use App\Services\Messaging\MessageDelivery;
use App\Services\OperationalNotifier;
use App\Services\PricingService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\MarketplaceLaunchSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Database\Seeders\PunoLaunchCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class MarketplaceLaunchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_thirty_published_lots_are_repeatable_filterable_and_notify_each_contributing_producer(): void
    {
        Http::preventStrayRequests();
        config(['commerce.show_test_labels' => false, 'commerce.test_whatsapp_enabled' => true]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class, ProductionCatalogSeeder::class, PunoLaunchCatalogSeeder::class, PaymentMethodSeeder::class]);
        User::factory()->withRole('admin')->create();
        User::factory()->withRole('coordinator')->create();
        $this->seed(MarketplaceLaunchSeeder::class);
        $this->seed(MarketplaceLaunchSeeder::class);
        $this->assertSame(60, Producer::where('seed_batch', MarketplaceLaunchSeeder::BATCH)->count());
        $this->assertSame(30, app(LotAvailability::class)->marketplace()->count());
        $this->assertSame(30, Lot::count());
        $lots = app(LotAvailability::class)->marketplace()->get();
        foreach ($lots as $lot) {
            $this->assertSame(now('America/Lima')->addDays(14)->toDateString(), $lot->available_until->toDateString());
            $this->assertCount(2, $lot->portions);
        }
        $this->get('/')->assertOk()->assertSee('30 lotes disponibles')->assertDontSee('entorno de prueba');
        $lot = $lots->first();
        $this->get(route('marketplace.index', ['product_id' => $lot->product_id]))->assertOk()
            ->assertViewHas('varieties', fn ($rows) => $rows->every(fn ($row) => $row->product_id === $lot->product_id))
            ->assertViewHas('qualities', fn ($rows) => $rows->every(fn ($row) => $row->product_id === $lot->product_id));
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $quote = app(PricingService::class)->issue($buyer, $lot, CommerceScenario::key(['quantity' => (string) $lot->available_quantity, 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Entrega coordinada en destino', 'payment_method' => 'coordinated']));
        $order = app(CheckoutService::class)->confirm($buyer, $quote, CommerceScenario::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
        $notices = OperationalNotice::where('order_id', $order->id)->whereNotNull('producer_id')->get();
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            app(OperationalNotifier::class)->deliver($notice->id);
            $message = $notice->fresh()->message;
            $this->assertSame('fake', $message->provider);
            $this->assertStringNotContainsString('PRUEBA', $message->body);
            $this->assertStringContainsString($order->code, $message->body);
            app(MessageDelivery::class)->deliver($message->id);
            app(MessageDelivery::class)->deliver($message->id);
            $this->assertSame('accepted', $message->fresh()->status);
        }
        $producer = $notices->first()->producer;
        $conversation = Conversation::factory()->create(['producer_id' => $producer->id, 'phone' => $producer->phone, 'phone_lookup' => $producer->phone_lookup, 'provider' => 'evolution', 'consent' => 'granted']);
        $message = $conversation->messages()->create(['direction' => 'outbound', 'provider' => 'evolution', 'account' => 'local', 'deduplication_key' => 'generated-contact-check', 'body' => 'Hola', 'status' => 'queued']);
        $this->assertSame('generated_contact', app(MessageDelivery::class)->blockedReason($message));
        Http::assertNothingSent();
    }
}
