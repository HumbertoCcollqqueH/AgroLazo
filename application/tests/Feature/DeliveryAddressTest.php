<?php

namespace Tests\Feature;

use App\Models\OperationalNotice;
use App\Models\User;
use App\Services\OperationalNotifier;
use App\Services\PricingService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class DeliveryAddressTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
        Http::preventStrayRequests();
    }

    public function test_incomplete_destinations_and_invalid_coordinates_cannot_be_quoted(): void
    {
        $scenario = new CommerceScenario;
        foreach ([[], ['latitude' => 91], ['longitude' => -181], ['street' => 'Puno'], ['phone' => 'abc']] as $invalid) {
            $address = $invalid === [] ? [] : [...CommerceScenario::address(), ...$invalid];
            try {
                app(PricingService::class)->issue($scenario->buyer, $scenario->order->quote->lot, CommerceScenario::key(['quantity' => '5.000', 'policy_id' => $scenario->order->quote->pricing_policy_id, 'delivery_details' => 'Entrega de prueba', 'delivery_address' => $address, 'payment_method' => 'coordinated']));
                $this->fail('La dirección incompleta no debe aceptarse.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_buyer_can_complete_an_old_order_privately_with_history_and_no_duplicate_update(): void
    {
        $scenario = new CommerceScenario;
        DB::table('orders')->where('id', $scenario->order->id)->update(['delivery_address' => null]);
        $data = CommerceScenario::key(['version' => $scenario->order->version, 'delivery_address' => CommerceScenario::address()]);
        $url = route('commerce.orders.delivery-address', $scenario->order);
        $this->actingAs(User::factory()->withRole('buyer')->create())->postJson($url, $data)->assertForbidden();
        $this->actingAs($scenario->buyer)->post($url, $data)->assertRedirect();
        $this->post($url, $data)->assertRedirect();
        $order = $scenario->order->fresh();
        $this->assertSame(2, $order->version);
        $this->assertSame(CommerceScenario::address(), $order->delivery_address);
        $this->assertSame(1, $order->events()->where('action', 'delivery_address_updated')->count());
        $event = $order->events()->where('action', 'delivery_address_updated')->sole();
        app(OperationalNotifier::class)->coordinators($event);
        $notice = OperationalNotice::query()->where('event_key', 'commerce:'.$event->id.':operator:'.$scenario->staff->id)->sole();
        $this->assertStringContainsString('el destino de entrega fue actualizado', $notice->body);
        $this->assertStringNotContainsString(CommerceScenario::address()['street'], $notice->body);
        $this->assertSame(0, OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$event->id.':%')->whereNotNull('producer_id')->count());
        $this->assertStringNotContainsString('Receptor de prueba', DB::table('orders')->where('id', $order->id)->value('delivery_address'));
        $this->get(route('commerce.orders.show', $order))->assertOk()->assertSee('Abrir ubicación en el mapa');
    }

    public function test_address_cannot_change_after_a_shipment_is_scheduled(): void
    {
        $scenario = new CommerceScenario;
        $physical = $scenario->consolidate($scenario->receive($scenario->schedule()));
        $scenario->ship($physical);
        $this->actingAs($scenario->buyer)->postJson(route('commerce.orders.delivery-address', $scenario->order), CommerceScenario::key(['version' => $scenario->order->fresh()->version, 'delivery_address' => CommerceScenario::address()]))->assertUnprocessable()->assertJsonValidationErrors('delivery_address');
    }
}
