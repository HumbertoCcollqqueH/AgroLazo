<?php

namespace Tests\Feature;

use App\Models\Producer;
use App\Services\ProducerIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProducerIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<string> */
    private array $environmentDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['producers.lookup_key' => str_repeat('a', 64)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    #[DataProvider('validPhones')]
    public function test_phone_normalization_preserves_e164_identity(string $input, string $expected): void
    {
        $this->assertSame($expected, app(ProducerIdentity::class)->normalizePhone($input));
    }

    public static function validPhones(): array
    {
        return [
            'móvil local peruano' => ['900000001', '+51900000001'],
            'móvil peruano E164' => ['+51900000001', '+51900000001'],
            'espacios exteriores' => [' 900000001 ', '+51900000001'],
            'internacional' => ['+12025550123', '+12025550123'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_invalid_phones_are_rejected_instead_of_guessing_identity(string $phone): void
    {
        $this->expectException(ValidationException::class);

        app(ProducerIdentity::class)->normalizePhone($phone);
    }

    public static function invalidPhones(): array
    {
        return [
            'vacío' => [''],
            'local que no es celular' => ['800000001'],
            'Perú con dígito faltante' => ['+5190000000'],
            'Perú con dígito adicional' => ['+519000000001'],
            'Perú que no es celular' => ['+51800000001'],
            'código sin signo' => ['51900000001'],
            'puntuación interna' => ['+51 900-000-001'],
            'extensión mezclada' => ['+51900000001x4'],
            'excede E164' => ['+1234567890123456'],
        ];
    }

    public function test_identity_is_encrypted_at_rest_and_sensitive_attributes_are_not_serialized(): void
    {
        $producer = Producer::factory()->create([
            'dni' => '00000001', 'phone' => '900000001', 'email' => 'privado@productor.test',
            'photo_path' => 'producers/private-photo.jpg',
        ]);

        $producer->refresh();

        $this->assertSame('00000001', $producer->dni);
        $this->assertSame('+51900000001', $producer->phone);
        $this->assertSame('privado@productor.test', $producer->email);
        $raw = DB::table('producers')->where('id', $producer->id)->first();
        $this->assertNotSame('00000001', $raw->dni);
        $this->assertNotSame('+51900000001', $raw->phone);
        $this->assertNotSame('privado@productor.test', $raw->email);
        $serialized = $producer->toArray();
        foreach (['dni', 'dni_lookup', 'phone', 'phone_lookup', 'email', 'photo_path'] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $serialized);
        }
        $this->assertStringNotContainsString('00000001', $producer->toJson());
        $this->assertStringNotContainsString('privado@productor.test', $producer->toJson());
    }

    public function test_normalized_phone_lookup_is_stable_and_dni_leading_zeroes_are_distinct(): void
    {
        $identity = app(ProducerIdentity::class);

        $local = $identity->lookup('phone', '900000001');
        $international = $identity->lookup('phone', '+51900000001');
        $firstDni = $identity->lookup('dni', '00000001');
        $secondDni = $identity->lookup('dni', '10000000');

        $this->assertSame($local, $international);
        $this->assertNotSame($firstDni, $secondDni);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $firstDni);
        $this->assertNotSame(hash('sha256', '00000001'), $firstDni);
        $this->assertDatabaseCount('producer_identity_keys', 1);
    }

    public function test_missing_private_lookup_key_fails_without_creating_a_fingerprint(): void
    {
        config(['producers.lookup_key' => null]);

        try {
            app(ProducerIdentity::class)->lookup('dni', '00000001');
            $this->fail('La identidad no debe operar sin su clave privada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('producer', $exception->errors());
        }

        $this->assertDatabaseCount('producer_identity_keys', 0);
        $this->assertDatabaseCount('producers', 0);
    }

    public function test_changing_the_key_cannot_silently_reindex_existing_producers(): void
    {
        $producer = Producer::factory()->create(['dni' => '00000001']);
        $originalLookup = $producer->dni_lookup;
        $originalFingerprint = DB::table('producer_identity_keys')->sole()->fingerprint;
        config(['producers.lookup_key' => str_repeat('b', 64)]);

        try {
            $producer->update(['full_name' => 'Cambio con clave incorrecta']);
            $this->fail('Una clave diferente debe bloquear cualquier reindexación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('producer', $exception->errors());
        }

        $this->assertSame($originalLookup, $producer->fresh()->dni_lookup);
        $this->assertSame($originalFingerprint, DB::table('producer_identity_keys')->sole()->fingerprint);
        $this->assertNotSame('Cambio con clave incorrecta', $producer->fresh()->full_name);
    }

    public function test_missing_fingerprint_with_existing_producers_is_not_recreated(): void
    {
        Producer::factory()->create();
        DB::table('producer_identity_keys')->delete();

        try {
            app(ProducerIdentity::class)->assertKeyMatchesDatabase();
            $this->fail('La huella ausente con productores existentes necesita restauración.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('producer', $exception->errors());
        }

        $this->assertDatabaseCount('producer_identity_keys', 0);
        $this->assertDatabaseCount('producers', 1);
    }

    public function test_dni_cannot_be_reassigned_even_through_a_direct_model_update(): void
    {
        $producer = Producer::factory()->create(['dni' => '00000001']);

        try {
            $producer->update(['dni' => '00000002']);
            $this->fail('El DNI original no puede modificarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dni', $exception->errors());
        }

        $this->assertSame('00000001', $producer->fresh()->dni);
    }

    public function test_producer_cannot_be_deleted_with_its_audit_identity(): void
    {
        $producer = Producer::factory()->archived()->create();

        try {
            $producer->delete();
            $this->fail('Los productores se archivan y no se eliminan.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('historial', $exception->getMessage());
        }

        $this->assertModelExists($producer);
    }

    #[DataProvider('publicationStates')]
    public function test_only_a_saved_verified_producer_is_eligible_to_publish(string $status, bool $eligible): void
    {
        $producer = Producer::factory()->create(['status' => $status]);

        $this->assertSame($eligible, $producer->canPublishOffers());
    }

    public static function publicationStates(): array
    {
        return [
            'pendiente' => ['pending_verification', false],
            'verificado' => ['verified', true],
            'rechazado' => ['rejected', false],
            'suspendido' => ['suspended', false],
            'archivado' => ['archived', false],
        ];
    }

    public function test_an_unsaved_verified_producer_is_not_eligible_to_publish(): void
    {
        $this->assertFalse(Producer::factory()->verified()->make()->canPublishOffers());
    }

    #[DataProvider('emptyKeyEnvironments')]
    public function test_prepare_key_generates_only_for_an_empty_database_and_preserves_other_env_lines(string $contents): void
    {
        config(['producers.lookup_key' => null]);
        $path = $this->temporaryEnvironment($contents);

        $this->artisan('agroconecta:prepare-producer-key')->assertSuccessful();

        $saved = File::get($path);
        $this->assertMatchesRegularExpression('/^PRODUCER_LOOKUP_KEY=[0-9a-f]{64}\r?$/m', $saved);
        $this->assertStringContainsString('APP_NAME=PruebaDeEntorno', $saved);
        $this->assertStringContainsString('MAIL_MAILER=array', $saved);
        $this->assertDatabaseCount('producer_identity_keys', 1);
    }

    public static function emptyKeyEnvironments(): array
    {
        return [
            'clave ausente' => ["APP_NAME=PruebaDeEntorno\nMAIL_MAILER=array\n"],
            'clave vacía antes de otra variable' => ["APP_NAME=PruebaDeEntorno\nPRODUCER_LOOKUP_KEY=\nMAIL_MAILER=array\n"],
            'clave con comillas vacías' => ["APP_NAME=PruebaDeEntorno\nPRODUCER_LOOKUP_KEY=\"\"\nMAIL_MAILER=array\n"],
            'clave vacía con finales Windows' => ["APP_NAME=PruebaDeEntorno\r\nPRODUCER_LOOKUP_KEY=\r\nMAIL_MAILER=array\r\n"],
        ];
    }

    public function test_prepare_key_preserves_an_existing_key_and_env_file_byte_for_byte(): void
    {
        $contents = 'PRODUCER_LOOKUP_KEY='.str_repeat('a', 64)."\nAPP_NAME=PruebaDeEntorno\n";
        $path = $this->temporaryEnvironment($contents);
        app(ProducerIdentity::class)->assertKeyMatchesDatabase();
        $fingerprint = DB::table('producer_identity_keys')->sole()->fingerprint;

        $this->artisan('agroconecta:prepare-producer-key')->assertSuccessful();

        $this->assertSame($contents, File::get($path));
        $this->assertSame($fingerprint, DB::table('producer_identity_keys')->sole()->fingerprint);
    }

    public function test_prepare_key_refuses_mismatched_configuration_without_replacing_the_file(): void
    {
        Producer::factory()->create();
        $contents = 'PRODUCER_LOOKUP_KEY='.str_repeat('b', 64)."\nAPP_NAME=PruebaDeEntorno\n";
        $path = $this->temporaryEnvironment($contents);
        config(['producers.lookup_key' => str_repeat('b', 64)]);

        $this->artisan('agroconecta:prepare-producer-key')->assertFailed();

        $this->assertSame($contents, File::get($path));
        $this->assertDatabaseCount('producers', 1);
    }

    public function test_prepare_key_refuses_to_generate_if_an_identity_fingerprint_exists(): void
    {
        app(ProducerIdentity::class)->assertKeyMatchesDatabase();
        config(['producers.lookup_key' => null]);
        $contents = "PRODUCER_LOOKUP_KEY=\nAPP_NAME=PruebaDeEntorno\n";
        $path = $this->temporaryEnvironment($contents);

        $this->artisan('agroconecta:prepare-producer-key')->assertFailed();

        $this->assertSame($contents, File::get($path));
        $this->assertDatabaseCount('producer_identity_keys', 1);
    }

    public function test_prepare_key_refuses_to_generate_if_producers_exist_without_a_fingerprint(): void
    {
        Producer::factory()->create();
        DB::table('producer_identity_keys')->delete();
        config(['producers.lookup_key' => null]);
        $contents = "APP_NAME=PruebaDeEntorno\n";
        $path = $this->temporaryEnvironment($contents);

        $this->artisan('agroconecta:prepare-producer-key')->assertFailed();

        $this->assertSame($contents, File::get($path));
        $this->assertDatabaseCount('producer_identity_keys', 0);
    }

    public function test_prepare_key_does_not_overwrite_an_env_key_that_has_not_been_loaded(): void
    {
        config(['producers.lookup_key' => null]);
        $contents = 'PRODUCER_LOOKUP_KEY='.str_repeat('c', 64)."\nAPP_NAME=PruebaDeEntorno\n";
        $path = $this->temporaryEnvironment($contents);

        $this->artisan('agroconecta:prepare-producer-key')->assertFailed();

        $this->assertSame($contents, File::get($path));
        $this->assertDatabaseCount('producer_identity_keys', 0);
    }

    private function temporaryEnvironment(string $contents): string
    {
        $directory = sys_get_temp_dir().'/agroconecta-producer-key-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $this->environmentDirectories[] = $directory;
        $path = $directory.'/.env';
        File::put($path, $contents);
        $this->app->useEnvironmentPath($directory);
        $this->app->loadEnvironmentFrom('.env');

        return $path;
    }
}
