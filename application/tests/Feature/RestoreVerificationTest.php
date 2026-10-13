<?php

namespace Tests\Feature;

use App\Models\Producer;
use App\Services\PaymentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class RestoreVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
    }

    private function manifest(): array
    {
        $this->assertSame(0, Artisan::call('agroconecta:verify-restore'));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_manifest_is_read_only_verifies_encryption_and_detects_changed_business_data(): void
    {
        $producer = Producer::factory()->verified()->create();
        $first = $this->manifest();
        $this->assertSame($first, $this->manifest());
        $this->assertGreaterThanOrEqual(2, $first['decrypted_fields']);
        $this->assertSame(1, $first['tables']['producers']['rows']);
        $this->assertStringNotContainsString($producer->dni, json_encode($first));
        $this->assertStringNotContainsString($producer->phone, json_encode($first));
        DB::table('producers')->where('id', $producer->id)->update(['full_name' => 'Otro nombre sintético']);
        $this->assertNotSame($first['database_sha256'], $this->manifest()['database_sha256']);
    }

    public function test_wrong_lookup_key_or_unreadable_ciphertext_fails_without_disclosing_data(): void
    {
        $producer = Producer::factory()->verified()->create();
        config(['producers.lookup_key' => str_repeat('b', 64)]);
        $this->artisan('agroconecta:verify-restore')->assertFailed();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        DB::table('producers')->where('id', $producer->id)->update(['dni' => 'not-a-valid-encrypted-payload']);
        $this->assertSame(1, Artisan::call('agroconecta:verify-restore'));
        $this->assertStringNotContainsString('not-a-valid-encrypted-payload', Artisan::output());
    }

    public function test_missing_or_corrupted_evidence_fails_and_restored_copy_passes(): void
    {
        $scenario = new CommerceScenario('25.125');
        $payment = $scenario->payment();
        $contents = "%PDF-1.4\nEVIDENCIA SINTETICA DE RESTAURACION\n%%EOF";
        $proof = app(PaymentService::class)->proof($scenario->buyer, $payment, CommerceScenario::key(), UploadedFile::fake()->createWithContent('prueba.pdf', $contents));
        $original = $this->manifest();
        $this->assertSame(1, $original['verified_file_references']);
        Storage::disk('local')->delete($proof->path);
        $this->artisan('agroconecta:verify-restore')->assertFailed();
        Storage::disk('local')->put($proof->path, 'corrupted');
        $this->artisan('agroconecta:verify-restore')->assertFailed();
        Storage::disk('local')->put($proof->path, $contents);
        $this->assertSame($original, $this->manifest());
    }
}
