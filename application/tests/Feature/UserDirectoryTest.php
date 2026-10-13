<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class UserDirectoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrator_can_combine_email_role_and_inactive_account_filters(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $matching = User::factory()->withRole('coordinator')->inactive()->create(['email' => 'equipo.puno@example.test']);
        User::factory()->withRole('coordinator')->create(['email' => 'otro.puno@example.test']);
        User::factory()->withRole('buyer')->inactive()->create(['email' => 'comprador.puno@example.test']);
        User::factory()->withRole('coordinator')->inactive()->create(['email' => 'equipo.cusco@example.test']);

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'PUNO@EXAMPLE', 'role' => 'coordinator', 'active' => '0']))
            ->assertOk()
            ->assertViewHas('users', fn (LengthAwarePaginator $users): bool => $users->total() === 1 && $users->first()->is($matching))
            ->assertSee('equipo.puno@example.test')
            ->assertSee('Gestionar acceso');
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $literal = User::factory()->withRole('buyer')->create(['name' => 'Productora 100%_Andes']);
        User::factory()->withRole('buyer')->create(['name' => 'Productora 100 Andina']);

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '100%_']))
            ->assertOk()
            ->assertViewHas('users', fn (LengthAwarePaginator $users): bool => $users->total() === 1 && $users->first()->is($literal));
    }

    public function test_pending_approval_filter_returns_only_active_current_buyers(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $pending = User::factory()->withRole('buyer')->create();
        Buyer::factory()->for($pending)->create();
        Buyer::factory()->approved()->for(User::factory()->withRole('buyer'))->create();
        Buyer::factory()->for(User::factory()->withRole('buyer')->inactive())->create();
        Buyer::factory()->for(User::factory()->withRole('coordinator'))->create();

        $this->actingAs($admin)->get(route('admin.users.index', ['buyer_status' => 'pending', 'active' => '1']))
            ->assertOk()
            ->assertViewHas('users', fn (LengthAwarePaginator $users): bool => $users->total() === 1 && $users->first()->is($pending))
            ->assertSee('Aprobar comprador');
    }

    public function test_empty_filters_keep_all_accounts_and_invalid_filters_are_rejected(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        User::factory()->withRole('coordinator')->inactive()->create();

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '', 'role' => '', 'active' => '', 'buyer_status' => '']))
            ->assertOk()->assertViewHas('users', fn (LengthAwarePaginator $users): bool => $users->total() === 2);

        $this->from(route('admin.users.index'))->get(route('admin.users.index', ['q' => ['unexpected'], 'role' => 'superuser', 'active' => 'all', 'buyer_status' => 'verified']))
            ->assertRedirectToRoute('admin.users.index')->assertSessionHasErrors(['q', 'role', 'active', 'buyer_status']);
    }

    public function test_directory_filters_do_not_grant_access_to_coordinators_or_buyers(): void
    {
        foreach (['coordinator', 'buyer'] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('admin.users.index', ['role' => 'admin']))->assertForbidden();
        }
    }

    public function test_failed_access_change_preserves_only_the_edited_accounts_form(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $target = User::factory()->withRole('coordinator')->create();
        User::factory()->withRole('buyer')->create();

        $this->actingAs($admin)->from(route('admin.users.index'))->patch(route('admin.users.update', $target), [
            'role' => 'buyer', 'active' => '0', 'reason' => 'no', 'access_user_id' => $target->id,
        ])->assertRedirectToRoute('admin.users.index')->assertSessionHasErrors('reason');

        $response = $this->get(route('admin.users.index'))->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'value="no"'));
        $this->assertMatchesRegularExpression('/<details[^>]*class="group border-t border-slate-100"[^>]*\sopen\s*>/', $html);
        $this->assertTrue($target->fresh()->active);
        $this->assertTrue($target->fresh()->hasRole('coordinator'));
    }
}
