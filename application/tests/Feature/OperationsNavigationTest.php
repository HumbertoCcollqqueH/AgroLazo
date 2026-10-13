<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OperationsNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrators_get_full_management_navigation_without_the_buyer_dashboard(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('op-shell--admin', false)
            ->assertSee('Navegación de administración')
            ->assertDontSee('Tus compras recientes')
            ->assertDontSee('class="ac-footer"', false)
            ->assertDontSee('ac-contact-widget', false)
            ->assertViewHas('buyerLotCount', 0);

        foreach (['admin.users.index', 'admin.users.create', 'commerce.pricing', 'payments.methods', 'whatsapp.connection', 'fulfillment.points', 'offers.index', 'lots.index'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
    }

    public function test_coordinators_have_an_operational_workspace_without_administrator_controls(): void
    {
        $coordinator = User::factory()->withRole('coordinator')->create();
        $response = $this->actingAs($coordinator)->get(route('dashboard'))->assertOk()
            ->assertSee('op-shell--coordinator', false)
            ->assertSee('Navegación de coordinación')
            ->assertDontSee('class="ac-footer"', false);

        foreach (['producers.index', 'offers.index', 'lots.index', 'commerce.index', 'messaging.index', 'fulfillment.points'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
        foreach (['admin.users.index', 'whatsapp.connection', 'commerce.pricing', 'payments.methods'] as $route) {
            $response->assertDontSee('href="'.route($route).'"', false);
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_buyer_design_and_store_preview_keep_the_existing_public_layout_without_recovery_notice(): void
    {
        config(['storefront.recovery_notice' => 'Datos recuperados al 20/09/2026.']);
        $buyer = User::factory()->withRole('buyer')->create();
        $this->actingAs($buyer)->get(route('dashboard'))->assertOk()
            ->assertSee('class="ac-dashboard"', false)
            ->assertSee('class="ac-footer"', false)
            ->assertDontSee('data-operations-sidebar', false)
            ->assertDontSee('Datos recuperados al');

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('marketplace.index'))->assertOk()
            ->assertSee('class="ac-footer"', false)
            ->assertDontSee('data-operations-sidebar', false)
            ->assertDontSee('Datos recuperados al');
    }

    public function test_operational_forms_keep_the_same_role_navigation_and_hide_revoked_permissions(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        foreach (['admin.users.index', 'producers.index', 'offers.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('op-shell--admin', false);
        }

        $admin->roles()->detach();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-operations-sidebar', false);
        $this->get(route('admin.users.index'))->assertForbidden();
    }
}
