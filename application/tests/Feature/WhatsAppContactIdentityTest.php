<?php

namespace Tests\Feature;

use App\Jobs\SyncWhatsAppContact;
use App\Models\Producer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\EvolutionConnectionManager;
use App\Services\ProducerRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppContactIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['messaging.evolution.url' => 'http://evolution:8080', 'messaging.evolution.api_key' => 'test-key', 'messaging.evolution.webhook_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Bus::fake();
        WhatsAppConnection::query()->create(['id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'agroconecta-qr', 'status' => 'connected', 'automation_enabled' => false, 'notice_text' => 'Aviso del canal para registrar y consultar las ofertas agrícolas.', 'notice_version' => 'v1', 'notice_approved' => false, 'revision' => 1, 'updated_by' => User::factory()->withRole('admin')->create()->id]);
    }

    public function test_identity_sync_queries_devices_for_the_current_registered_phone_without_sending_a_message(): void
    {
        $producer = Producer::factory()->verified()->create(['phone' => '+51900000801']);
        Http::fake(['*/baileys/getUSyncDevices/agroconecta-qr' => Http::response([])]);
        (new SyncWhatsAppContact($producer->id))->handle(app(EvolutionConnectionManager::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['jids'] === ['51900000801@s.whatsapp.net'] && $request['useCache'] === false && str_contains($request->url(), '/baileys/getUSyncDevices/'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_suspended_missing_and_disconnected_contacts_do_not_trigger_a_provider_request(): void
    {
        $producer = Producer::factory()->create(['status' => 'suspended']);
        $manager = app(EvolutionConnectionManager::class);
        $manager->syncContact($producer->id);
        $manager->syncContact(999999);
        $producer->update(['status' => 'verified']);
        WhatsAppConnection::query()->find(1)->update(['status' => 'disconnected']);
        $manager->syncContact($producer->id);
        Http::assertNothingSent();
    }

    public function test_reconnecting_schedules_registered_identities_once_without_reactivating_responses(): void
    {
        config(['queue.default' => 'redis']);
        $producer = Producer::factory()->verified()->create();
        Producer::factory()->create(['status' => 'suspended']);
        WhatsAppConnection::query()->find(1)->update(['status' => 'disconnected']);
        $manager = app(EvolutionConnectionManager::class);
        $manager->connectionEvent('agroconecta-qr', 'open');
        $manager->connectionEvent('agroconecta-qr', 'open');
        Bus::assertDispatchedTimes(SyncWhatsAppContact::class, 1);
        Bus::assertDispatched(SyncWhatsAppContact::class, fn ($job) => $job->producerId === $producer->id);
        $this->assertFalse(WhatsAppConnection::query()->find(1)->automation_enabled);
        Http::assertNothingSent();
    }

    public function test_registering_or_changing_a_phone_schedules_its_identity_sync(): void
    {
        config(['queue.default' => 'redis']);
        $actor = User::factory()->withRole('admin')->create();
        $registry = app(ProducerRegistry::class);
        $producer = $registry->create($actor, ['full_name' => 'Productor de prueba', 'dni' => '12345678', 'phone' => '+51900000801', 'type' => 'individual']);
        Bus::assertDispatchedTimes(SyncWhatsAppContact::class, 1);
        $registry->changePhone($actor, $producer, ['current_phone' => '+51900000801', 'new_phone' => '+51900000802', 'reason' => 'Cambio autorizado de teléfono']);
        Bus::assertDispatchedTimes(SyncWhatsAppContact::class, 2);
        Http::assertNothingSent();
    }

    public function test_messaging_shows_whatsapp_state_instead_of_the_unrelated_sms_switch(): void
    {
        config(['messaging.live_enabled' => true]);
        $this->actingAs(User::factory()->withRole('admin')->create())->get(route('messaging.index'))->assertOk()->assertSee('Respuestas de WhatsApp')->assertSee('Pausadas')->assertSee('Configurar respuestas');
        $this->get(route('whatsapp.connection'))->assertOk()->assertSee('Las respuestas automáticas están pausadas.');
    }
}
