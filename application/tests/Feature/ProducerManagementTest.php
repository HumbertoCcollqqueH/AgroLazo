<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Producer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProducerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['producers.lookup_key' => str_repeat('a', 64)]);
    }

    public function test_admin_and_coordinator_can_operate_producers_but_buyer_cannot(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $coordinator = User::factory()->withRole('coordinator')->create();
        $buyer = User::factory()->withRole('buyer')->create();
        $producer = Producer::factory()->create();

        foreach (['view-producers', 'manage-producers', 'verify-producers'] as $ability) {
            $this->assertTrue($admin->can($ability));
            $this->assertTrue($coordinator->can($ability));
            $this->assertFalse($buyer->can($ability));
        }
        foreach (['view', 'update', 'verify'] as $ability) {
            $this->assertTrue($admin->can($ability, $producer));
            $this->assertTrue($coordinator->can($ability, $producer));
            $this->assertFalse($buyer->can($ability, $producer));
        }
        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($admin->can($ability, Producer::class));
            $this->assertTrue($coordinator->can($ability, Producer::class));
            $this->assertFalse($buyer->can($ability, Producer::class));
        }
    }

    public function test_guest_is_redirected_and_buyer_cannot_read_or_mutate_private_producer_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $producer = Producer::factory()->create();
        $buyer = User::factory()->withRole('buyer')->create();
        $this->get(route('producers.index'))->assertRedirectToRoute('login');

        $this->actingAs($buyer);
        foreach (['index', 'create', 'show', 'edit'] as $action) {
            $this->get(route('producers.'.$action, $producer))->assertForbidden();
        }
        $this->post(route('producers.store'), $this->registration())->assertForbidden();
        $this->patch(route('producers.update', $producer), ['full_name' => 'Nombre cambiado', 'reason' => 'Intento sin permiso'])->assertForbidden();
        $this->post(route('producers.verify', $producer), $this->decision('verified'))->assertForbidden();
        $this->patch(route('producers.change-phone', $producer), [
            'current_phone' => $producer->phone, 'new_phone' => '900000002', 'reason' => 'Intento sin permiso',
        ])->assertForbidden();
        $this->post(route('producers.consent', $producer), $this->consent('granted'))->assertForbidden();

        $this->assertDatabaseCount('producers', 1);
        $this->assertSame('pending_verification', $producer->fresh()->status);
        $this->assertDatabaseCount('producer_verifications', 0);
        $this->assertDatabaseCount('producer_phone_changes', 0);
        $this->assertDatabaseCount('producer_consents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_views_render_and_the_list_masks_private_identifiers(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create(['dni' => '00000001', 'phone' => '+51900000001']);

        $response = $this->actingAs($coordinator)->get(route('producers.index'));

        $response->assertViewIs('producers.index')->assertSee($producer->full_name)
            ->assertDontSee('00000001')->assertDontSee('+51900000001');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('producers.create'))->assertViewIs('producers.form');
        $this->get(route('producers.show', $producer))->assertViewIs('producers.show')->assertSee('00000001');
        $this->get(route('producers.edit', $producer))->assertViewIs('producers.form');
    }

    public function test_registration_requires_only_four_business_fields_and_never_sends_messages(): void
    {
        $coordinator = $this->staff();
        Http::preventStrayRequests();
        Notification::fake();

        $this->actingAs($coordinator)->post(route('producers.store'), $this->registration())->assertSessionHasNoErrors();

        $producer = Producer::query()->sole();
        $this->assertSame('Productora de prueba', $producer->full_name);
        $this->assertSame('00000001', $producer->dni);
        $this->assertSame('+51900000001', $producer->phone);
        $this->assertSame('pending_verification', $producer->status);
        $this->assertSame($coordinator->id, $producer->registered_by);
        $this->assertFalse($producer->canPublishOffers());
        $this->assertDatabaseCount('producer_verifications', 0);
        $this->assertDatabaseCount('producer_consents', 0);
        $this->assertDatabaseCount('audit_logs', 1);
        Notification::assertNothingSent();
    }

    public function test_registration_rejects_injected_status_and_registration_actor(): void
    {
        $coordinator = $this->staff();

        $this->actingAs($coordinator)->postJson(route('producers.store'), [
            ...$this->registration(), 'status' => 'verified', 'registered_by' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'registered_by']);

        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_registration_saves_multiple_products_and_displays_them_in_the_profile(): void
    {
        $coordinator = $this->staff();
        $potato = Product::factory()->create(['name' => 'Papa']);
        $quinoa = Product::factory()->create(['name' => 'Quinua']);
        $inactive = Product::factory()->inactive()->create(['name' => 'Producto inactivo']);

        $this->actingAs($coordinator)->get(route('producers.create'))
            ->assertSee('Productos que produce')->assertSee('Papa')->assertSee('Quinua')
            ->assertDontSee($inactive->name);
        $this->post(route('producers.store'), [
            ...$this->registration(), 'product_ids' => [$potato->id, $quinoa->id],
        ])->assertSessionHasNoErrors();

        $producer = Producer::query()->sole();
        $this->assertEqualsCanonicalizing([$potato->id, $quinoa->id], $producer->products->modelKeys());
        $this->assertDatabaseCount('producer_product', 2);
        $this->get(route('producers.show', $producer))->assertSee('Papa')->assertSee('Quinua');
        $this->get(route('producers.edit', $producer))->assertViewHas('producer', fn (Producer $profile): bool => $profile->products->count() === 2);
    }

    public function test_product_changes_are_audited_and_can_be_cleared_without_resetting_verification(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->verified()->create();
        $potato = Product::factory()->create(['name' => 'Papa']);
        $quinoa = Product::factory()->create(['name' => 'Quinua']);
        $producer->products()->attach($potato);

        $this->actingAs($coordinator)->patch(route('producers.update', $producer), [
            'product_ids' => [$quinoa->id], 'reason' => 'Producto actualizado con el productor',
        ])->assertRedirectToRoute('producers.show', $producer);

        $change = $producer->profileChanges()->sole();
        $this->assertSame(['before' => [$potato->id], 'after' => [$quinoa->id]], $change->changes['product_ids']);
        $this->assertSame($coordinator->id, $change->changed_by);
        $this->assertSame('verified', $producer->fresh()->status);
        $this->get(route('producers.show', $producer))->assertSee('Papa → Quinua');

        $this->patch(route('producers.update', $producer), [
            'product_ids' => '', 'reason' => 'Retiro de productos por confirmar',
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $producer->products()->get()->modelKeys());
        $this->assertDatabaseCount('producer_profile_changes', 2);
        $this->assertAuditExcludes(['Producto actualizado con el productor', 'Retiro de productos por confirmar']);
    }

    public function test_profile_edits_preserve_products_when_not_supplied_and_allow_existing_inactive_references(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();
        $product = Product::factory()->inactive()->create();
        $producer->products()->attach($product);

        $this->actingAs($coordinator)->patch(route('producers.update', $producer), [
            'association' => 'Asociación actualizada', 'reason' => 'Actualización de la asociación',
        ])->assertSessionHasNoErrors();
        $this->patch(route('producers.update', $producer), [
            'product_ids' => [$product->id], 'reason' => 'Conservación del dato registrado',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$product->id], $producer->products()->get()->modelKeys());
        $this->assertDatabaseCount('producer_profile_changes', 1);
        $this->get(route('producers.edit', $producer))->assertSee('Referencia anterior inactiva');
    }

    public function test_new_product_assignments_reject_inactive_products_categories_and_units(): void
    {
        $coordinator = $this->staff();
        $inactiveProduct = Product::factory()->inactive()->create();
        $inactiveCategory = Product::factory()->create();
        $inactiveCategory->category->update(['active' => false]);
        $inactiveUnit = Product::factory()->create();
        $inactiveUnit->baseUnit->update(['active' => false]);

        $this->actingAs($coordinator);
        foreach ([$inactiveProduct, $inactiveCategory, $inactiveUnit] as $product) {
            $this->postJson(route('producers.store'), [
                ...$this->registration(), 'product_ids' => [$product->id],
            ])->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        }

        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseCount('producer_product', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_product_selection_rejects_duplicates_and_unknown_catalog_entries(): void
    {
        $coordinator = $this->staff();
        $product = Product::factory()->create();

        $this->actingAs($coordinator)->postJson(route('producers.store'), [
            ...$this->registration(), 'product_ids' => [$product->id, $product->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');
        $this->postJson(route('producers.store'), [
            ...$this->registration(), 'product_ids' => [999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');

        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseCount('producer_product', 0);
    }

    public function test_missing_registration_fields_have_spanish_validation_errors(): void
    {
        $coordinator = $this->staff();

        $response = $this->actingAs($coordinator)->postJson(route('producers.store'), []);

        $response->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'dni', 'phone', 'type']);
        $this->assertStringContainsString('obligatorio', $response->json('errors.dni.0'));
        $this->assertDatabaseCount('producers', 0);
    }

    #[DataProvider('invalidRegistrationFields')]
    public function test_invalid_registration_is_rejected_without_persisting_data(string $field, mixed $value, ?string $errorField = null): void
    {
        $coordinator = $this->staff();
        $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());

        $this->actingAs($coordinator)->postJson(route('producers.store'), [
            ...$this->registration(), $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($errorField ?? $field);

        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidRegistrationFields(): array
    {
        return [
            'DNI de siete dígitos' => ['dni', '1234567'],
            'DNI no numérico' => ['dni', '1234567A'],
            'DNI numérico pierde ceros' => ['dni', 12345678],
            'celular peruano incompleto' => ['phone', '+5190000000'],
            'tipo desconocido' => ['type', 'wholesale'],
            'fecha futura' => ['birth_date', '2026-09-13'],
            'correo inválido' => ['email', 'sin-arroba'],
            'coordenada sin pareja' => ['latitude', '-15.8', 'longitude'],
        ];
    }

    public function test_duplicate_dni_and_equivalent_phone_formats_are_rejected_even_after_archival(): void
    {
        $coordinator = $this->staff();
        Producer::factory()->archived()->create(['dni' => '00000001', 'phone' => '+51900000001']);

        $this->actingAs($coordinator)->postJson(route('producers.store'), [
            ...$this->registration(), 'phone' => '900000002',
        ])->assertUnprocessable()->assertJsonValidationErrors('dni');
        $this->postJson(route('producers.store'), [
            ...$this->registration(), 'dni' => '00000002',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('producers', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_verification_records_the_actual_actor_time_and_private_evidence(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();
        $this->travelTo(now()->setDate(2026, 9, 12)->setTime(12, 0, 0, 0));
        $notes = 'Evidencia privada revisada: DNI 00000001 y teléfono +51900000001.';

        $this->actingAs($coordinator)->post(route('producers.verify', $producer), [
            ...$this->decision('verified'), 'notes' => $notes,
        ])->assertRedirectToRoute('producers.show', $producer);

        $verification = $producer->verifications()->sole();
        $this->assertSame('verified', $producer->fresh()->status);
        $this->assertTrue($producer->fresh()->canPublishOffers());
        $this->assertSame($coordinator->id, $verification->verified_by);
        $this->assertSame('verified', $verification->result);
        $this->assertSame('manual_document', $verification->method);
        $this->assertTrue($verification->verified_at->equalTo(now()));
        $this->assertSame($notes, $verification->notes);
        $this->assertNotSame($notes, $verification->getRawOriginal('notes'));
        $this->assertStringNotContainsString($notes, $verification->toJson());
        $this->assertAuditExcludes(['00000001', '+51900000001', $notes]);
    }

    public function test_verification_cannot_forge_a_responsible_user_or_timestamp(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();

        $this->actingAs($coordinator)->postJson(route('producers.verify', $producer), [
            ...$this->decision('verified'), 'verified_by' => 999999, 'verified_at' => '2000-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['verified_by', 'verified_at']);

        $this->assertSame('pending_verification', $producer->fresh()->status);
        $this->assertDatabaseCount('producer_verifications', 0);
    }

    #[DataProvider('permittedTransitions')]
    public function test_permitted_state_transitions_keep_a_verification_history(string $from, string $to): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create(['status' => $from]);

        $this->actingAs($coordinator)->post(route('producers.verify', $producer), $this->decision($to))
            ->assertRedirectToRoute('producers.show', $producer);

        $this->assertSame($to, $producer->fresh()->status);
        $this->assertDatabaseHas('producer_verifications', [
            'producer_id' => $producer->id, 'result' => $to, 'verified_by' => $coordinator->id,
        ]);
        $this->assertDatabaseCount('producer_verifications', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function permittedTransitions(): array
    {
        return [
            'rechazo inicial' => ['pending_verification', 'rejected'],
            'archivo inicial' => ['pending_verification', 'archived'],
            'revisión después de rechazo' => ['rejected', 'pending_verification'],
            'archivo después de rechazo' => ['rejected', 'archived'],
            'suspensión' => ['verified', 'suspended'],
            'archivo verificado' => ['verified', 'archived'],
            'restitución' => ['suspended', 'verified'],
            'archivo suspendido' => ['suspended', 'archived'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_invalid_state_transition_does_not_create_history(string $from, string $to): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create(['status' => $from]);

        $this->actingAs($coordinator)->postJson(route('producers.verify', $producer), $this->decision($to))
            ->assertUnprocessable()->assertJsonValidationErrors('result');

        $this->assertSame($from, $producer->fresh()->status);
        $this->assertDatabaseCount('producer_verifications', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function forbiddenTransitions(): array
    {
        return [
            'suspender sin verificar' => ['pending_verification', 'suspended'],
            'aprobar rechazado sin reapertura' => ['rejected', 'verified'],
            'repetir verificación' => ['verified', 'verified'],
            'rechazar ya verificado' => ['verified', 'rejected'],
        ];
    }

    public function test_archived_producer_is_read_only_and_preserves_identity(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->archived()->create();

        $this->actingAs($coordinator)->get(route('producers.show', $producer))->assertViewIs('producers.show');
        $this->get(route('producers.edit', $producer))->assertForbidden();
        $this->patch(route('producers.update', $producer), ['association' => 'Otra asociación', 'reason' => 'Cambio archivado'])->assertForbidden();
        $this->post(route('producers.verify', $producer), $this->decision('verified'))->assertForbidden();
        $this->patch(route('producers.change-phone', $producer), [
            'current_phone' => $producer->phone, 'new_phone' => '900000002', 'reason' => 'Cambio archivado',
        ])->assertForbidden();

        $this->assertModelExists($producer);
        $this->assertSame('archived', $producer->fresh()->status);
        $this->assertFalse($producer->fresh()->canPublishOffers());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_profile_edit_cannot_change_dni_phone_status_or_original_registrar(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();

        $this->actingAs($coordinator)->patchJson(route('producers.update', $producer), [
            'dni' => '00000003', 'phone' => '900000003', 'status' => 'verified',
            'registered_by' => $coordinator->id, 'reason' => 'Intento de cambio de identidad',
        ])->assertUnprocessable()->assertJsonValidationErrors(['dni', 'phone', 'status', 'registered_by']);

        $this->assertSame($producer->dni, $producer->fresh()->dni);
        $this->assertSame($producer->phone, $producer->fresh()->phone);
        $this->assertSame('pending_verification', $producer->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_identity_correction_requires_another_verification_but_optional_profile_edit_does_not(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->verified()->create();

        $this->actingAs($coordinator)->patch(route('producers.update', $producer), [
            'association' => 'Asociación de prueba', 'reason' => 'Completar ficha opcional',
        ])->assertRedirectToRoute('producers.show', $producer);
        $this->assertSame('verified', $producer->fresh()->status);
        $this->assertSame('Asociación de prueba', $producer->fresh()->association);
        $this->assertDatabaseCount('producer_verifications', 0);

        $this->patch(route('producers.update', $producer), [
            'full_name' => 'Nombre corregido de prueba', 'reason' => 'Corrección sustentada del nombre',
        ])->assertRedirectToRoute('producers.show', $producer);

        $this->assertSame('pending_verification', $producer->fresh()->status);
        $this->assertFalse($producer->fresh()->canPublishOffers());
        $this->assertSame('Nombre corregido de prueba', $producer->fresh()->full_name);
        $this->assertDatabaseHas('producer_verifications', [
            'producer_id' => $producer->id, 'result' => 'pending_verification', 'method' => 'administrative',
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_phone_change_checks_the_current_number_and_keeps_encrypted_history(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->verified()->create(['phone' => '+51900000001']);
        $reason = 'Cambio asistido del +51900000001 al +51900000002.';

        $this->actingAs($coordinator)->patch(route('producers.change-phone', $producer), [
            'current_phone' => '900000001', 'new_phone' => '900000002', 'reason' => $reason,
        ])->assertRedirectToRoute('producers.show', $producer);

        $producer->refresh();
        $change = $producer->phoneChanges()->sole();
        $this->assertSame('+51900000002', $producer->phone);
        $this->assertSame('pending_verification', $producer->status);
        $this->assertSame('+51900000001', $change->previous_phone);
        $this->assertSame('+51900000002', $change->new_phone);
        $this->assertSame($coordinator->id, $change->changed_by);
        $this->assertSame($reason, $change->reason);
        $this->assertNotSame($change->previous_phone, $change->getRawOriginal('previous_phone'));
        $this->assertNotSame($change->new_phone, $change->getRawOriginal('new_phone'));
        $this->assertStringNotContainsString('+51900000001', $change->toJson());
        $this->assertStringNotContainsString('+51900000002', $change->toJson());
        $this->assertAuditExcludes(['+51900000001', '+51900000002', $reason]);
    }

    public function test_profile_correction_preserves_private_before_and_after_values_and_its_reason(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create([
            'full_name' => 'Nombre anterior de prueba', 'email' => 'anterior@productor.test',
            'association' => 'Asociación anterior',
        ]);
        $reason = 'Se corrigieron datos privados mediante revisión con DNI 00000001.';

        $this->actingAs($coordinator)->patch(route('producers.update', $producer), [
            'full_name' => 'Nombre actual de prueba', 'email' => 'actual@productor.test',
            'association' => 'Asociación actual', 'address' => 'Dirección privada actual 321', 'reason' => $reason,
        ])->assertRedirectToRoute('producers.show', $producer);

        $change = $producer->profileChanges()->sole();
        $this->assertSame(['before' => 'Nombre anterior de prueba', 'after' => 'Nombre actual de prueba'], $change->changes['full_name']);
        $this->assertSame(['before' => 'anterior@productor.test', 'after' => 'actual@productor.test'], $change->changes['email']);
        $this->assertSame(['before' => 'Asociación anterior', 'after' => 'Asociación actual'], $change->changes['association']);
        $this->assertSame(['before' => null, 'after' => $producer->currentLocation()->sole()->id], $change->changes['location_version_id']);
        $this->assertSame($reason, $change->reason);
        $this->assertSame($coordinator->id, $change->changed_by);
        $this->assertStringNotContainsString('anterior@productor.test', $change->getRawOriginal('changes'));
        $this->assertNotSame($reason, $change->getRawOriginal('reason'));
        $this->assertArrayNotHasKey('changes', $change->toArray());
        $this->assertArrayNotHasKey('reason', $change->toArray());
        $this->assertSame('pending_verification', $producer->fresh()->status);
        $this->assertDatabaseCount('producer_verifications', 0);
        $this->assertAuditExcludes(['anterior@productor.test', 'actual@productor.test', 'Dirección privada actual 321', $reason]);

        try {
            $change->update(['reason' => 'Intento de alterar evidencia']);
            $this->fail('La corrección histórica debe conservarse sin cambios.');
        } catch (LogicException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame($reason, $change->fresh()->reason);
    }

    public function test_saving_unchanged_profile_values_does_not_invent_a_correction_or_audit(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->verified()->create([
            'association' => 'Asociación vigente', 'email' => 'vigente@productor.test', 'birth_date' => '1980-06-15',
        ]);

        $this->actingAs($coordinator)->patch(route('producers.update', $producer), [
            'full_name' => $producer->full_name, 'type' => $producer->type, 'association' => $producer->association,
            'email' => 'vigente@productor.test', 'birth_date' => '1980-06-15', 'reason' => 'Revisión sin cambios reales',
        ])->assertRedirectToRoute('producers.show', $producer);

        $this->assertSame('verified', $producer->fresh()->status);
        $this->assertDatabaseCount('producer_profile_changes', 0);
        $this->assertDatabaseCount('producer_verifications', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_wrong_current_phone_and_another_producers_phone_are_rejected(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->verified()->create(['phone' => '+51900000001']);
        Producer::factory()->archived()->create(['phone' => '+51900000002']);

        $this->actingAs($coordinator)->patchJson(route('producers.change-phone', $producer), [
            'current_phone' => '900000003', 'new_phone' => '900000004', 'reason' => 'Cambio asistido de prueba',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_phone');
        $this->patchJson(route('producers.change-phone', $producer), [
            'current_phone' => '900000001', 'new_phone' => '900000002', 'reason' => 'Cambio asistido de prueba',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_phone');

        $this->assertSame('+51900000001', $producer->fresh()->phone);
        $this->assertSame('verified', $producer->fresh()->status);
        $this->assertDatabaseCount('producer_phone_changes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_location_changes_close_the_previous_version_and_protect_address_from_audit(): void
    {
        $coordinator = $this->staff();
        $location = Location::factory()->create();
        $this->freezeTime();
        $this->actingAs($coordinator)->post(route('producers.store'), [
            ...$this->registration(), 'location_id' => $location->id, 'community' => 'Comunidad inicial privada',
            'address' => 'Dirección inicial privada 123', 'latitude' => '-15.840200', 'longitude' => '-70.021900',
        ])->assertSessionHasNoErrors();
        $producer = Producer::query()->sole();
        $previous = $producer->currentLocation()->sole();
        $this->travel(1)->minutes();

        $this->patch(route('producers.update', $producer), [
            'location_id' => $location->id, 'community' => 'Comunidad actual privada',
            'address' => 'Dirección actual privada 456', 'latitude' => '-15.810000', 'longitude' => '-70.010000',
            'reason' => 'Actualización asistida del domicilio',
        ])->assertRedirectToRoute('producers.show', $producer);

        $current = $producer->currentLocation()->sole();
        $this->assertNotSame($previous->id, $current->id);
        $this->assertNotNull($previous->fresh()->valid_until);
        $this->assertSame('Dirección inicial privada 123', $previous->fresh()->address);
        $this->assertSame('Dirección actual privada 456', $current->address);
        $this->assertSame('-15.810000', $current->latitude);
        $this->assertSame($coordinator->id, $current->recorded_by);
        $this->assertNotSame($current->address, $current->getRawOriginal('address'));
        $this->assertDatabaseCount('producer_locations', 2);
        $this->assertAuditExcludes(['Dirección inicial privada 123', 'Dirección actual privada 456', 'Comunidad actual privada', '-15.810000']);
    }

    public function test_inactive_location_ancestor_and_out_of_range_coordinates_are_rejected(): void
    {
        $coordinator = $this->staff();
        $country = Location::factory()->inactive()->create();
        $department = Location::factory()->create(['type' => 'department', 'parent_id' => $country->id]);

        $this->actingAs($coordinator)->postJson(route('producers.store'), [
            ...$this->registration(), 'location_id' => $department->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->postJson(route('producers.store'), [
            ...$this->registration(), 'latitude' => '-91', 'longitude' => '181',
        ])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseCount('producer_locations', 0);
    }

    public function test_consent_is_recorded_per_purpose_and_preserves_grant_and_withdrawal(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();
        $this->freezeTime();

        $this->actingAs($coordinator)->post(route('producers.consent', $producer), $this->consent('granted'))
            ->assertRedirectToRoute('producers.show', $producer);
        $this->travel(1)->minutes();
        $this->post(route('producers.consent', $producer), $this->consent('declined'))
            ->assertRedirectToRoute('producers.show', $producer);

        $decisions = $producer->consents()->orderBy('id')->get();
        $this->assertSame(['granted', 'declined'], $decisions->pluck('decision')->all());
        $this->assertSame(['whatsapp_messages', 'whatsapp_messages'], $decisions->pluck('purpose')->all());
        $this->assertSame(['aviso-prueba-v1', 'aviso-prueba-v1'], $decisions->pluck('notice_version')->all());
        $this->assertSame([$coordinator->id, $coordinator->id], $decisions->pluck('recorded_by')->all());
        $this->assertDatabaseCount('producer_consents', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_consent_requires_a_supported_purpose_and_notice_and_cannot_forge_the_actor(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create();

        $this->actingAs($coordinator)->postJson(route('producers.consent', $producer), [
            'purpose' => 'all_future_uses', 'decision' => 'granted', 'recorded_by' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['purpose', 'notice_version', 'recorded_by']);

        $this->assertDatabaseCount('producer_consents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_private_profile_text_is_escaped_and_name_search_treats_wildcards_literally(): void
    {
        $coordinator = $this->staff();
        $producer = Producer::factory()->create([
            'full_name' => 'Productor 100% <script>alert("nombre")</script>',
            'association' => '<script>alert("asociacion")</script>',
        ]);
        Producer::factory()->create(['full_name' => 'Productor sin porcentaje']);

        $this->actingAs($coordinator)->get(route('producers.index', ['q' => '%']))
            ->assertSee($producer->full_name)->assertDontSee('Productor sin porcentaje')
            ->assertDontSee('<script>alert("nombre")</script>', false);
        $this->get(route('producers.show', $producer))
            ->assertSee($producer->association)->assertDontSee('<script>alert("asociacion")</script>', false);
    }

    private function staff(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->withRole('coordinator')->create();
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return ['full_name' => 'Productora de prueba', 'dni' => '00000001', 'phone' => '900000001', 'type' => 'individual'];
    }

    /** @return array<string, string> */
    private function decision(string $result): array
    {
        return ['result' => $result, 'method' => 'manual_document', 'notes' => 'Revisión documental de prueba realizada por el coordinador.'];
    }

    /** @return array<string, string> */
    private function consent(string $decision): array
    {
        return ['purpose' => 'whatsapp_messages', 'notice_version' => 'aviso-prueba-v1', 'decision' => $decision];
    }

    /** @param list<string> $privateValues */
    private function assertAuditExcludes(array $privateValues): void
    {
        $audit = DB::table('audit_logs')->get()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($privateValues as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
    }
}
