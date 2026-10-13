<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class OrderJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CommerceScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
        $this->scenario = new CommerceScenario;
    }

    public function test_confirmed_order_explains_pending_collection_payment_and_simulated_notices(): void
    {
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))
            ->assertOk()
            ->assertSee('Compra de prueba: los avisos al productor se procesan únicamente en el simulador.')
            ->assertSee('Punto y horario pendientes de confirmar.')
            ->assertSee('Confirmar la compra no acredita el pago.')
            ->assertDontSee('Programar o cambiar acopio')
            ->assertDontSee('Avisos de la operación');

        $this->actingAs($this->scenario->staff)->get(route('commerce.orders.show', $this->scenario->order))
            ->assertOk()
            ->assertSee('Programa el punto y horario de acopio.')
            ->assertSee('Confirmar programación y preparar avisos');
    }

    public function test_scheduled_collection_shows_its_real_snapshot_and_next_step(): void
    {
        $collection = $this->scenario->schedule();

        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))
            ->assertOk()
            ->assertSee($collection->point_snapshot['name'])
            ->assertSee($collection->point_snapshot['address'])
            ->assertSee($collection->starts_at->setTimezone('America/Lima')->format('d/m/Y H:i'))
            ->assertSee('El equipo está reuniendo y pesando el producto.')
            ->assertDontSee('Punto y horario pendientes de confirmar.');
    }

    public function test_another_buyer_cannot_access_the_order_journey(): void
    {
        $otherBuyer = User::factory()->withRole('buyer')->create();

        $this->actingAs($otherBuyer)->get(route('commerce.orders.show', $this->scenario->order))->assertForbidden();
    }
}
