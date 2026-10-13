<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Location;
use App\Models\Message;
use App\Models\Offer;
use App\Models\Producer;
use App\Models\ProducerLocation;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProducerEasyOfferTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Producer $producer;

    private Product $quinua;

    private Location $puno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-25T23:00:00Z'));
        config(['messaging.registered_contacts_only' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->producer = Producer::factory()->verified()->create();
        $this->quinua = Product::query()->where('slug', 'quinua')->firstOrFail();
        $this->puno = Location::query()->where('name', 'Puno')->firstOrFail();
        $this->producer->products()->attach($this->quinua);
        ProducerLocation::factory()->create(['producer_id' => $this->producer->id, 'location_id' => $this->puno->id]);
        Http::preventStrayRequests();
    }

    private function receive(string $body, ?string $id = null): Message
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', $id ?? (string) Str::uuid(), $this->producer->phone, $body, CarbonImmutable::now());
        $this->assertNotNull($message);

        return $message;
    }

    private function say(string $body, ?string $id = null): Conversation
    {
        $message = $this->receive($body, $id);
        app(ConversationEngine::class)->process($message->conversation_id);

        return $message->conversation->fresh();
    }

    private function accept(): Conversation
    {
        $this->say('HOLA');

        return $this->say('ACEPTO');
    }

    private function reply(Conversation $conversation): string
    {
        return $conversation->messages()->where('direction', 'outbound')->latest('id')->firstOrFail()->body;
    }

    private function review(): Conversation
    {
        $this->accept();
        $this->say('VENDER');
        $this->say('20 kg');
        $this->say('1');
        $conversation = $this->say('2');
        $this->assertSame('OFFER_REVIEW', $conversation->state);

        return $conversation;
    }

    public function test_registered_product_location_and_unspecified_classifications_skip_redundant_questions(): void
    {
        $this->accept();
        $conversation = $this->say('VENDER');

        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertSame($this->quinua->id, $conversation->context['draft']['product_id']);
        $this->assertSame($this->puno->id, $conversation->context['draft']['location_id']);
        $this->assertSame($this->quinua->varieties()->where('code', 'unspecified')->value('id'), $conversation->context['draft']['variety_id']);
        $this->assertSame($this->quinua->qualityGrades()->where('code', 'unspecified')->value('id'), $conversation->context['draft']['quality_grade_id']);
        $this->assertStringContainsString('Quinua', $this->reply($conversation));
        $this->assertStringNotContainsString('¿Qué producto', $this->reply($conversation));
        $this->assertStringNotContainsString('¿Dónde', $this->reply($conversation));
        $conversation = $this->say('20');
        $this->assertSame('OFFER_FROM', $conversation->state);
        $this->assertSame('20', $conversation->context['draft']['quantity']);
        $this->assertDatabaseCount('offers', 0);
    }

    #[DataProvider('simpleConfirmations')]
    public function test_simple_confirmation_registers_one_offer_and_retries_do_not_duplicate_it(string $confirmation): void
    {
        $draft = $this->review()->context['draft'];
        $messageId = (string) Str::uuid();
        $conversation = $this->say($confirmation, $messageId);

        $this->assertSame('READY', $conversation->state);
        $this->assertDatabaseCount('offers', 1);
        $this->say($confirmation, $messageId);
        $this->say($confirmation);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('offer_histories', 1);
        $offer = Offer::query()->sole();
        $this->assertSame($this->producer->id, $offer->producer_id);
        $this->assertSame('20.000', $offer->quantity);
        $this->assertSame($draft['available_from'], $offer->available_from->toDateString());
        $this->assertSame($draft['available_until'], $offer->available_until->toDateString());
        $this->assertSame('pending_verification', $offer->status);
        Http::assertNothingSent();
    }

    public static function simpleConfirmations(): array
    {
        return [['CONFIRMAR'], ['confirmar'], ['CONFIRMO'], ['SÍ'], ['1']];
    }

    #[DataProvider('tokenSeparators')]
    public function test_existing_token_confirmation_still_accepts_a_space_or_a_pasted_code(string $separator): void
    {
        $conversation = $this->review();
        $conversation = $this->say('CONFIRMAR'.$separator.$conversation->context['token']);

        $this->assertSame('READY', $conversation->state);
        $this->assertDatabaseCount('offers', 1);
    }

    public static function tokenSeparators(): array
    {
        return [[' '], ['']];
    }

    public function test_existing_review_without_new_metadata_can_be_confirmed_after_its_saved_summary(): void
    {
        $conversation = $this->review();
        $context = $conversation->context;
        unset($context['review_message_id']);
        $conversation->update(['context' => $context]);

        $conversation = $this->say('CONFIRMAR');

        $this->assertSame('READY', $conversation->state);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_confirmation_received_before_the_summary_is_generated_cannot_submit_the_offer(): void
    {
        $this->accept();
        $this->say('VENDER');
        $this->say('20');
        $this->say('1');
        $until = $this->receive('2');
        $this->receive('CONFIRMAR');

        app(ConversationEngine::class)->process($until->conversation_id);

        $this->assertSame('OFFER_REVIEW', $until->conversation->fresh()->state);
        $this->assertDatabaseCount('offers', 0);
        $this->say('CONFIRMAR');
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_confirmation_before_completing_fields_does_not_submit_or_discard_the_draft(): void
    {
        $this->accept();
        $conversation = $this->say('VENDER');
        $key = $conversation->context['draft_key'];

        $conversation = $this->say('CONFIRMAR');

        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertSame($key, $conversation->context['draft_key']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_greeting_menu_and_sell_preserve_the_reviewed_draft(): void
    {
        $conversation = $this->review();
        $draft = $conversation->context['draft'];
        $key = $conversation->context['draft_key'];

        foreach (['HOLA', 'MENU', 'VENDER'] as $body) {
            $conversation = $this->say($body);
            $this->assertSame('OFFER_REVIEW', $conversation->state);
            $this->assertSame($draft, $conversation->context['draft']);
            $this->assertSame($key, $conversation->context['draft_key']);
        }
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_edit_menu_preserves_fields_and_quantity_correction_invalidates_the_old_token(): void
    {
        $conversation = $this->review();
        $draft = $conversation->context['draft'];
        $key = $conversation->context['draft_key'];
        $token = $conversation->context['token'];

        $conversation = $this->say('2');
        $this->assertSame('OFFER_EDIT', $conversation->state);
        $this->assertSame($draft, $conversation->context['draft']);
        $this->assertSame($key, $conversation->context['draft_key']);
        $conversation = $this->say('1');
        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $conversation = $this->say('30');
        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertNotSame($key, $conversation->context['draft_key']);
        $this->assertSame('30', $conversation->context['draft']['quantity']);
        foreach (['product_id', 'location_id', 'available_from', 'available_until'] as $field) {
            $this->assertSame($draft[$field], $conversation->context['draft'][$field]);
        }
        $this->say('CONFIRMAR '.$token);
        $this->assertDatabaseCount('offers', 0);
        $this->say('CONFIRMAR');
        $this->assertDatabaseHas('offers', ['producer_id' => $this->producer->id, 'quantity' => '30.000']);
    }

    public function test_returning_from_edit_menu_does_not_replace_the_existing_draft(): void
    {
        $conversation = $this->review();
        $draft = $conversation->context['draft'];
        $key = $conversation->context['draft_key'];
        $this->say('CORREGIR');

        $conversation = $this->say('0');

        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertSame($draft, $conversation->context['draft']);
        $this->assertSame($key, $conversation->context['draft_key']);
    }

    public function test_location_can_be_corrected_without_changing_the_producer_profile_or_other_offer_fields(): void
    {
        $cusco = Location::factory()->create(['name' => 'Cusco', 'type' => 'department', 'parent_id' => $this->puno->parent_id]);
        $conversation = $this->review();
        $draft = $conversation->context['draft'];

        $conversation = $this->say('UBICACION');
        $this->assertSame('OFFER_LOCATION', $conversation->state);
        $this->assertContains($cusco->id, $conversation->context['choices']);
        $conversation = $this->say('Cusco');

        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertSame($cusco->id, $conversation->context['draft']['location_id']);
        foreach (['product_id', 'quantity', 'available_from', 'available_until'] as $field) {
            $this->assertSame($draft[$field], $conversation->context['draft'][$field]);
        }
        $this->assertSame($this->puno->id, $this->producer->currentLocation()->firstOrFail()->location_id);
        $this->assertDatabaseCount('producer_locations', 1);
    }

    public function test_two_registered_products_show_only_the_producers_usable_choices(): void
    {
        $papa = Product::query()->where('slug', 'papa')->firstOrFail();
        $unassigned = Product::factory()->create(['name' => 'Cebada']);
        $this->producer->products()->attach($papa);
        $this->accept();

        $conversation = $this->say('VENDER');

        $this->assertSame('OFFER_PRODUCT', $conversation->state);
        $this->assertEqualsCanonicalizing([$this->quinua->id, $papa->id], array_values($conversation->context['choices']));
        $this->assertNotContains($unassigned->id, $conversation->context['choices']);
        $this->assertStringContainsString('Otro producto del catálogo', $this->reply($conversation));
        $option = (string) array_search($papa->id, $conversation->context['choices'], true);
        $conversation = $this->say($option);
        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertSame($papa->id, $conversation->context['draft']['product_id']);
    }

    public function test_a_profile_with_only_unavailable_products_does_not_silently_offer_the_global_catalog(): void
    {
        $this->quinua->update(['active' => false]);
        $this->accept();

        $conversation = $this->say('VENDER');

        $this->assertEmpty($conversation->context['choices'] ?? []);
        $this->assertArrayNotHasKey('product_id', $conversation->context['draft'] ?? []);
        $this->assertStringNotContainsString('1 Papa', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_explicit_unavailable_product_cannot_be_silently_replaced_by_the_profile_default(): void
    {
        $this->quinua->update(['active' => false]);
        $this->accept();

        $conversation = $this->say('tengo 20 kilos de quinua');

        $this->assertArrayNotHasKey('product_id', $conversation->context['draft'] ?? []);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_only_usable_history_product_is_a_fallback_for_a_profile_without_registered_products(): void
    {
        $this->producer->products()->detach();
        Offer::factory()->create(['producer_id' => $this->producer->id, 'product_id' => $this->quinua->id, 'location_id' => $this->puno->id]);
        $this->accept();

        $conversation = $this->say('VENDER');

        $this->assertSame('OFFER_PRODUCT', $conversation->state);
        $this->assertArrayNotHasKey('product_id', $conversation->context['draft']);
        $this->assertContains($this->quinua->id, $conversation->context['choices']);
        $conversation = $this->say('1');
        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_missing_current_location_is_requested_instead_of_reusing_an_expired_location(): void
    {
        $this->producer->currentLocation()->firstOrFail()->update(['valid_until' => now()]);
        $this->accept();
        $this->say('VENDER');

        $conversation = $this->say('20');

        $this->assertSame('OFFER_LOCATION', $conversation->state);
        $this->assertArrayNotHasKey('location_id', $conversation->context['draft']);
        $this->assertContains($this->puno->id, $conversation->context['choices']);
    }

    public function test_inactive_registered_location_is_not_assumed_or_shown_as_a_choice(): void
    {
        $this->puno->update(['active' => false]);
        $this->accept();
        $this->say('VENDER');

        $conversation = $this->say('20');

        $this->assertSame('OFFER_LOCATION', $conversation->state);
        $this->assertArrayNotHasKey('location_id', $conversation->context['draft']);
        $this->assertNotContains($this->puno->id, $conversation->context['choices']);
    }

    public function test_one_real_variety_is_still_asked_instead_of_assumed(): void
    {
        $this->quinua->varieties()->update(['active' => false]);
        $variety = ProductVariety::factory()->create(['product_id' => $this->quinua->id, 'name' => 'Blanca', 'code' => 'blanca']);
        $this->accept();

        $conversation = $this->say('VENDER');

        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertArrayNotHasKey('variety_id', $conversation->context['draft']);
        $this->assertSame([$variety->id], array_values($conversation->context['choices']));
    }

    public function test_empty_classification_catalogs_continue_with_assisted_review(): void
    {
        $this->quinua->varieties()->update(['active' => false]);
        $this->quinua->qualityGrades()->update(['active' => false]);
        $this->accept();

        $conversation = $this->say('VENDER');

        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertNull($conversation->context['draft']['variety_id']);
        $this->assertNull($conversation->context['draft']['quality_grade_id']);
    }

    public function test_numeric_dates_use_lima_today_and_include_the_start_day_in_the_duration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-26T02:00:00Z'));
        $this->accept();
        $this->say('VENDER');
        $this->say('20');

        $conversation = $this->say('2');
        $this->assertSame('OFFER_UNTIL', $conversation->state);
        $this->assertSame('2026-09-26', $conversation->context['draft']['available_from']);
        $conversation = $this->say('3');

        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertSame('2026-10-10', $conversation->context['draft']['available_until']);
    }

    public function test_date_correction_preserves_product_quantity_and_location(): void
    {
        $conversation = $this->review();
        $draft = $conversation->context['draft'];
        $conversation = $this->say('FECHAS');
        $this->assertSame('OFFER_FROM', $conversation->state);
        $this->say('27/09/2026');

        $conversation = $this->say('1');

        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertSame('2026-09-27', $conversation->context['draft']['available_from']);
        $this->assertSame('2026-09-27', $conversation->context['draft']['available_until']);
        foreach (['product_id', 'quantity', 'location_id'] as $field) {
            $this->assertSame($draft[$field], $conversation->context['draft'][$field]);
        }
    }

    public function test_confirming_on_the_next_day_requests_new_dates_without_losing_the_offer_details(): void
    {
        $conversation = $this->review();
        $draft = $conversation->context['draft'];
        $this->travel(1)->days();

        $conversation = $this->say('CONFIRMAR');

        $this->assertSame('OFFER_FROM', $conversation->state);
        foreach (['product_id', 'quantity', 'unit_id', 'location_id', 'variety_id', 'quality_grade_id'] as $field) {
            $this->assertSame($draft[$field], $conversation->context['draft'][$field]);
        }
        $this->assertArrayNotHasKey('available_from', $conversation->context['draft']);
        $this->assertArrayNotHasKey('available_until', $conversation->context['draft']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_a_product_removed_from_the_profile_after_review_cannot_be_confirmed(): void
    {
        $this->review();
        $papa = Product::query()->where('slug', 'papa')->firstOrFail();
        $this->producer->products()->sync([$papa->id]);

        $conversation = $this->say('CONFIRMAR');

        $this->assertSame('OFFER_PRODUCT', $conversation->state);
        $this->assertArrayNotHasKey('product_id', $conversation->context['draft']);
        $this->assertSame([$papa->id], array_values($conversation->context['choices']));
        $this->assertSame($this->puno->id, $conversation->context['draft']['location_id']);
        $this->assertDatabaseCount('offers', 0);
    }

    #[DataProvider('simpleConfirmations')]
    public function test_expired_review_cannot_be_confirmed_or_reinterpreted_as_a_new_offer(string $confirmation): void
    {
        $this->review();
        $this->travel(8)->days();

        $conversation = $this->say($confirmation);

        $this->assertSame('READY', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_number_three_in_review_cancels_only_the_current_draft(): void
    {
        $existing = Offer::factory()->create(['producer_id' => $this->producer->id, 'product_id' => $this->quinua->id, 'location_id' => $this->puno->id, 'quantity' => '50.000']);
        $this->review();

        $conversation = $this->say('3');

        $this->assertSame('READY', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertDatabaseCount('offers', 1);
        $this->assertSame('50.000', $existing->fresh()->quantity);
    }
}
