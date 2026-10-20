<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Producer;
use App\Models\ProducerPayoutProfile;
use App\Models\User;
use App\Services\ProducerPayoutProfiles;
use App\Services\SettlementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class ProducerPayoutProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        $owner = User::factory()->withRole('producer')->create();
        $producer = Producer::factory()->verified()->create();
        $producer->user_id = $owner->id;
        $producer->save();

        return $owner;
    }

    private function data(array $overrides = []): array
    {
        return ['submission_key' => (string) Str::uuid(), 'current_version' => 0, 'holder_name' => 'Titular Productor', 'yape_phone' => '912345678', 'account_confirmed' => '1', ...$overrides];
    }

    private function qr(): File
    {
        return UploadedFile::fake()->createWithContent('qr.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lL8AAAAASUVORK5CYII='));
    }

    public function test_owner_saves_encrypted_private_qr_without_confirming_a_payment(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('producer.payout-profile.save'), $this->data(['qr' => $this->qr()]))->assertSessionHasNoErrors()->assertRedirect(route('producer.profile'));
        $profile = ProducerPayoutProfile::query()->sole();
        $this->assertSame($owner->producer->id, $profile->producer_id);
        $this->assertSame('912345678', $profile->yape_phone);
        $this->assertNotSame($profile->holder_name, DB::table('producer_payout_profiles')->value('holder_name'));
        $this->assertNotSame($profile->yape_phone, DB::table('producer_payout_profiles')->value('yape_phone'));
        $this->assertNotSame($profile->qr_path, DB::table('producer_payout_profiles')->value('qr_path'));
        Storage::disk('local')->assertExists($profile->qr_path);
        $this->get(route('producer.profile'))->assertOk()->assertSee('Guardar mi cuenta de cobro')->assertSee('912345678');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('producer_payouts', 0);
        $this->assertStringNotContainsString('912345678', AuditLog::query()->where('action', 'producer.payout_profile_saved')->sole()->toJson());
        Http::assertNothingSent();
    }

    public function test_qr_is_only_accessible_to_owner_and_authorized_payment_staff(): void
    {
        $owner = $this->owner();
        $profile = app(ProducerPayoutProfiles::class)->save($owner, $this->data(), $this->qr());
        $url = route('producer.payout-profile.qr', $profile);
        $this->get($url)->assertRedirect(route('login'));
        $response = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get($url)->assertOk();
        foreach ([$this->owner(), User::factory()->withRole('buyer')->create(), User::factory()->withRole('coordinator')->create()] as $other) {
            $this->actingAs($other)->get($url)->assertNotFound();
        }
        DB::table('users')->where('id', $owner->id)->update(['active' => false]);
        $this->actingAs($owner->fresh())->getJson($url)->assertForbidden();
    }

    public function test_invalid_images_missing_consent_and_invalid_phone_are_rejected(): void
    {
        $this->actingAs($this->owner());
        foreach ([
            UploadedFile::fake()->createWithContent('qr.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('qr.jpg', '<html>not an image</html>'),
            $this->qr()->size(2049),
        ] as $file) {
            $this->post(route('producer.payout-profile.save'), $this->data(['qr' => $file]))->assertSessionHasErrors('qr');
        }
        $this->post(route('producer.payout-profile.save'), $this->data(['account_confirmed' => '0', 'yape_phone' => '1234']))->assertSessionHasErrors(['account_confirmed', 'yape_phone']);
        $this->assertDatabaseCount('producer_payout_profiles', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_retries_do_not_duplicate_files_and_stale_changes_cannot_overwrite_new_data(): void
    {
        $owner = $this->owner();
        $data = $this->data(['qr' => $this->qr()]);
        $this->actingAs($owner)->post(route('producer.payout-profile.save'), $data)->assertSessionHasNoErrors();
        $this->post(route('producer.payout-profile.save'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('producer_payout_profiles', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->post(route('producer.payout-profile.save'), [...$data, 'yape_phone' => '987654321'])->assertConflict();
        $this->post(route('producer.payout-profile.save'), $this->data())->assertSessionHasErrors('current_version');
        $this->actingAs($this->owner())->post(route('producer.payout-profile.save'), $data)->assertConflict();
        $this->assertDatabaseCount('producer_payout_profiles', 1);
    }

    public function test_changing_account_clears_current_qr_and_preserves_previous_version(): void
    {
        $owner = $this->owner();
        $profiles = app(ProducerPayoutProfiles::class);
        $first = $profiles->save($owner, $this->data(), $this->qr());
        $second = $profiles->save($owner, $this->data(['current_version' => 1, 'yape_phone' => '+51 987 654 321']), null);
        $this->assertSame(2, $second->version);
        $this->assertSame('987654321', $second->yape_phone);
        $this->assertNull($second->qr_path);
        $this->assertSame('912345678', $first->fresh()->yape_phone);
        Storage::disk('local')->assertExists($first->qr_path);
        $this->actingAs($owner)->get(route('producer.payout-profile.qr', $first))->assertOk();
        $this->get(route('producer.payout-profile.qr', $second))->assertNotFound();
    }

    public function test_payout_freezes_account_and_qr_version_without_marking_payment_as_sent(): void
    {
        $this->seed(PaymentMethodSeeder::class);
        $scenario = new CommerceScenario;
        $scenario->receive($scenario->schedule());
        $settlement = $scenario->approve($scenario->settlement());
        $owner = User::factory()->withRole('producer')->create();
        $producer = $settlement->producer;
        $producer->user_id = $owner->id;
        $producer->save();
        $profiles = app(ProducerPayoutProfiles::class);
        $first = $profiles->save($owner, $this->data(), $this->qr());
        $service = app(SettlementService::class);
        $data = CommerceScenario::key(['amount' => '10.00', 'reason' => 'Desembolso autorizado al titular declarado en su cuenta.']);
        $payout = $service->authorize($scenario->admin, $settlement, $data);
        $profiles->save($owner, $this->data(['current_version' => 1, 'yape_phone' => '987654321']), null);
        $this->assertSame('authorized', $payout->fresh()->status);
        $this->assertNull($payout->fresh()->confirmed_at);
        $this->assertSame($first->id, $payout->recipient_snapshot['profile_id']);
        $this->assertSame('912345678', $payout->recipient_snapshot['yape_phone']);
        $this->assertStringNotContainsString('912345678', DB::table('producer_payouts')->where('id', $payout->id)->value('recipient_snapshot'));
        $this->assertArrayNotHasKey('recipient_snapshot', $payout->toArray());
        $this->assertTrue($payout->is($service->authorize($scenario->admin, $settlement, $data)));
        $this->assertDatabaseCount('producer_payouts', 1);
        Http::assertNothingSent();
    }

    public function test_operator_cannot_change_producer_account_and_other_producer_id_is_ignored(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->actingAs(User::factory()->withRole('admin')->create())->post(route('producer.payout-profile.save'), $this->data())->assertForbidden();
        $this->actingAs($owner)->post(route('producer.payout-profile.save'), $this->data(['producer_id' => $other->producer->id]))->assertSessionHasNoErrors();
        $this->assertSame($owner->producer->id, ProducerPayoutProfile::query()->sole()->producer_id);
        $this->assertNull(app(ProducerPayoutProfiles::class)->current($other, $other->producer));
    }
}
