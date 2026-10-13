<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_visitors_and_buyers_see_products_on_the_home_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->get('/')->assertOk()->assertViewIs('marketplace.index')->assertDontSee('Estado de los servicios');
        $buyer = User::factory()->withRole('buyer')->create();
        $this->actingAs($buyer)->get('/')->assertOk()->assertViewIs('marketplace.index')->assertDontSee('href="'.route('admin.users.index').'"', false);
    }

    public function test_login_lands_buyers_in_products_and_operators_in_administration(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['buyer' => 'marketplace.index', 'admin' => 'dashboard', 'coordinator' => 'dashboard'] as $role => $route) {
            $user = User::factory()->withRole($role)->create(['password' => 'Navigation-test-2026!']);
            $this->post('/login', ['email' => $user->email, 'password' => 'Navigation-test-2026!'])->assertRedirectToRoute($route);
            $this->post('/logout');
        }
    }

    public function test_administrators_have_their_own_home_and_revoked_permissions_disappear_on_next_request(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get('/')->assertRedirectToRoute('dashboard');
        $this->get('/marketplace')->assertSee('href="'.route('admin.users.index').'"', false);
        $admin->roles()->detach();
        $this->get('/marketplace')->assertDontSee('href="'.route('admin.users.index').'"', false);
        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_a_requested_product_page_is_preserved_after_login(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $buyer = User::factory()->withRole('buyer')->create(['password' => 'Navigation-test-2026!']);
        $this->withSession(['url.intended' => '/marketplace?sort=price_asc'])
            ->post('/login', ['email' => $buyer->email, 'password' => 'Navigation-test-2026!'])
            ->assertRedirect('/marketplace?sort=price_asc');
    }
}
