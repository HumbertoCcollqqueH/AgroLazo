<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Producer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagingSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->coordinator = User::factory()->withRole('coordinator')->create();
        config(['commerce.show_test_labels' => false]);
        Http::preventStrayRequests();
    }

    private function conversation(string $name, string $phone, array $attributes = []): Conversation
    {
        $producer = Producer::factory()->verified()->create(['full_name' => $name, 'phone' => $phone]);

        return Conversation::factory()->create([
            'producer_id' => $producer->id,
            'phone' => $producer->phone,
            'phone_lookup' => $producer->phone_lookup,
            'consent' => 'granted',
            'state' => 'READY',
            ...$attributes,
        ]);
    }

    public function test_coordinator_can_search_partial_names_without_exposing_full_phone_numbers(): void
    {
        $match = $this->conversation('José Mamani Quispe', '+51987000189');
        $this->conversation('María Condori', '+51987000190');

        $this->actingAs($this->coordinator)
            ->get(route('messaging.index', ['q' => 'mamani']))
            ->assertOk()
            ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $match->id)
            ->assertSee('José Mamani Quispe')
            ->assertDontSee('María Condori')
            ->assertDontSee('+51987000189')
            ->assertDontSee('Simulación')
            ->assertDontSee('Configurar respuestas')
            ->assertSee('Paso de la conversación');
        Http::assertNothingSent();
    }

    public function test_phone_search_normalizes_peruvian_formats_and_uses_the_private_identity_index(): void
    {
        $match = $this->conversation('José Mamani', '+51987000189');
        $this->conversation('Juan Mamani', '+51987000190');

        foreach (['987 000 189', '+51 (987) 000-189', '51987000189'] as $phone) {
            $this->actingAs($this->coordinator)
                ->get(route('messaging.index', ['q' => $phone]))
                ->assertOk()
                ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $match->id);
        }
        $this->get(route('messaging.index', ['q' => '189']))->assertSessionHasErrors('q');
    }

    public function test_name_search_matches_with_or_without_accents_and_ignores_case(): void
    {
        $accented = $this->conversation('José Muñoz', '+51987000189');
        $plain = $this->conversation('Jose Munoz', '+51987000190');
        $this->conversation('María Condori', '+51987000191');

        foreach (['Jose', 'José', 'JOSÉ', 'munoz', 'MUÑOZ', 'JOSE MUNOZ'] as $name) {
            $this->actingAs($this->coordinator)
                ->get(route('messaging.index', ['q' => $name]))
                ->assertOk()
                ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 2
                    && $rows->pluck('id')->sort()->values()->all() === [$accented->id, $plain->id])
                ->assertDontSee('María Condori');
        }
    }

    public function test_channel_attention_state_and_delivery_filters_combine(): void
    {
        $match = $this->conversation('Rosa Flores', '+51987000191', ['human_assigned' => true, 'state' => 'PRICE_REVIEW']);
        Message::factory()->create(['conversation_id' => $match->id, 'direction' => 'outbound', 'status' => 'blocked']);
        $other = $this->conversation('Mario Flores', '+51987000192', ['human_assigned' => true, 'state' => 'PRICE_REVIEW']);
        Message::factory()->create(['conversation_id' => $other->id, 'direction' => 'inbound', 'status' => 'failed']);
        $this->conversation('Luisa Flores', '+51987000193', ['human_assigned' => true, 'state' => 'PRICE_REVIEW', 'channel' => 'sms']);

        $this->actingAs($this->coordinator)
            ->get(route('messaging.index', ['q' => 'Flores', 'channel' => 'whatsapp', 'attention' => 'coordinator', 'state' => 'PRICE_REVIEW', 'delivery' => 'issues']))
            ->assertOk()
            ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $match->id && $rows->first()->delivery_issues_count === 1)
            ->assertSee('1 envío por revisar');
    }

    public function test_automatic_filter_excludes_pending_consent_and_staff_assigned_conversations(): void
    {
        $automatic = $this->conversation('Ana Choque', '+51987000194');
        $this->conversation('Luis Choque', '+51987000195', ['consent' => 'pending']);
        $this->conversation('Marta Choque', '+51987000196', ['human_assigned' => true]);

        $this->actingAs($this->coordinator)
            ->get(route('messaging.index', ['attention' => 'automatic']))
            ->assertOk()
            ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $automatic->id);
    }

    public function test_empty_results_and_literal_searches_do_not_fall_back_to_all_contacts(): void
    {
        $this->conversation('Josefa Apaza', '+51987000197');

        $this->actingAs($this->coordinator)
            ->get(route('messaging.index', ['q' => '%']))
            ->assertOk()
            ->assertViewHas('conversations', fn ($rows): bool => $rows->total() === 0)
            ->assertSee('No encontramos conversaciones con estos filtros.')
            ->assertDontSee('Josefa Apaza');
        $this->get(route('messaging.index', ['channel' => 'email']))->assertSessionHasErrors('channel');
    }

    public function test_search_keeps_authorization_and_only_administrators_see_connection_settings(): void
    {
        $this->get(route('messaging.index', ['q' => 'Mamani']))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->withRole('buyer')->create())
            ->get(route('messaging.index', ['q' => 'Mamani']))->assertForbidden();

        $this->actingAs(User::factory()->withRole('admin')->create())
            ->get(route('messaging.index'))->assertOk()->assertSee('Configurar respuestas');
    }
}
