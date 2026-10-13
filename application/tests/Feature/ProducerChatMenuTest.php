<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Location;
use App\Models\Offer;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProducerChatMenuTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Producer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.registered_contacts_only' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        foreach (Product::query()->get() as $product) {
            ProductVariety::factory()->create(['product_id' => $product->id, 'name' => 'Variedad de prueba']);
            QualityGrade::factory()->create(['product_id' => $product->id]);
        }
        $this->producer = Producer::factory()->verified()->create(['full_name' => 'Productora de Prueba']);
        Http::preventStrayRequests();
    }

    private function say(string $body): Conversation
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(), $this->producer->phone, $body, CarbonImmutable::now());
        $this->assertNotNull($message);
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

    private function offer(array $attributes): Offer
    {
        return Offer::factory()->recycle(Location::query()->where('name', 'Puno')->firstOrFail())->create($attributes);
    }

    public function test_menu_requires_consent_and_greets_the_registered_producer(): void
    {
        $conversation = $this->say('quiero agregar producto');
        $this->assertSame('WAITING_CONSENT', $conversation->state);
        $this->assertStringContainsString('ACEPTO', $this->reply($conversation));
        $this->assertArrayNotHasKey('draft', $conversation->context);

        $conversation = $this->say('ACEPTO');
        $this->assertSame('READY', $conversation->state);
        $this->assertSame('granted', $conversation->consent);
        $this->assertStringContainsString('Hola, Productora de Prueba.', $this->reply($conversation));
        $this->assertStringContainsString('1 Agregar producto', $this->reply($conversation));
        $this->assertStringContainsString('2 Consultar estado', $this->reply($conversation));
        $this->assertStringContainsString('3 Hablar con un coordinador', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 0);
        Http::assertNothingSent();
    }

    public function test_greetings_and_unknown_text_keep_ready_without_starting_an_offer(): void
    {
        $this->accept();
        foreach (['HOLA', 'menú', 'ACEPTO', 'gracias', '9'] as $body) {
            $conversation = $this->say($body);
            $this->assertSame('READY', $conversation->state);
            $this->assertArrayNotHasKey('draft', $conversation->context);
            $this->assertStringContainsString('1 Agregar producto', $this->reply($conversation));
        }
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_history_lists_only_own_usable_products_and_can_open_the_full_catalog(): void
    {
        $quinua = Product::query()->where('slug', 'quinua')->firstOrFail();
        $papa = Product::query()->where('slug', 'papa')->firstOrFail();
        $inactive = Product::factory()->inactive()->create(['name' => 'Producto retirado']);
        $this->offer(['producer_id' => $this->producer->id, 'product_id' => $quinua->id]);
        $this->offer(['producer_id' => $this->producer->id, 'product_id' => $inactive->id]);
        $this->offer(['product_id' => $papa->id]);

        $conversation = $this->accept();
        $this->assertStringContainsString('Productos en tus ofertas anteriores: Quinua.', $this->reply($conversation));
        $this->assertStringNotContainsString('Papa', $this->reply($conversation));
        $this->assertStringNotContainsString('Producto retirado', $this->reply($conversation));

        $conversation = $this->say('1');
        $this->assertSame('OFFER_PRODUCT', $conversation->state);
        $conversation = $this->say('1');
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame($quinua->id, $conversation->context['draft']['product_id']);
        $conversation = $this->say('PRODUCTO');
        $this->assertSame('OFFER_PRODUCT', $conversation->state);
        $this->assertSame([$quinua->id], array_values($conversation->context['choices']));
        $this->assertStringContainsString('0 Otro producto del catálogo', $this->reply($conversation));
        $conversation = $this->say('0');
        $this->assertSame('catalog', $conversation->context['product_scope']);
        $this->assertContains($papa->id, $conversation->context['choices']);
        $this->assertNotContains($inactive->id, $conversation->context['choices']);
        $conversation = $this->say((string) array_search($papa->id, $conversation->context['choices'], true));
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame($papa->id, $conversation->context['draft']['product_id']);
        $this->assertDatabaseCount('offers', 3);
    }

    public function test_natural_quantity_request_starts_a_new_draft_without_adding_existing_stock(): void
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $existing = $this->offer(['producer_id' => $this->producer->id, 'product_id' => $product->id, 'quantity' => '50.000', 'normalized_quantity' => '50.000']);
        $this->accept();
        $conversation = $this->say('tengo 80 kilos de quinua');

        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame($product->id, $conversation->context['draft']['product_id']);
        $this->assertSame('80', $conversation->context['draft']['quantity']);
        $this->assertSame('50.000', $existing->fresh()->quantity);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_numbered_selections_and_bare_quantity_require_review_and_confirmation(): void
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $existing = $this->offer(['producer_id' => $this->producer->id, 'product_id' => $product->id, 'quantity' => '50.000', 'normalized_quantity' => '50.000']);
        $this->accept();
        $this->say('quiero agregar producto');
        $this->say('1');
        $this->say('1');
        $conversation = $this->say('0');
        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertStringContainsString('cantidad ofreces en kg', $this->reply($conversation));
        $this->say('80');
        $this->say('Puno');
        $this->say('HOY');
        $conversation = $this->say('MAÑANA');
        $this->assertSame('OFFER_REVIEW', $conversation->state);
        $this->assertStringContainsString('Resumen: Quinua, 80 kg', $this->reply($conversation));
        $this->assertStringContainsString('1 o CONFIRMAR', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 1);

        $token = $conversation->context['token'];
        $this->say('CONFIRMAR '.$token);
        $this->say('CONFIRMAR '.$token);
        $this->assertDatabaseCount('offers', 2);
        $created = Offer::query()->latest('id')->firstOrFail();
        $this->assertSame('pending_verification', $created->status);
        $this->assertSame('80.000', $created->quantity);
        $this->assertSame('50.000', $existing->fresh()->quantity);
        Http::assertNothingSent();
    }

    public function test_greetings_and_menu_preserve_an_in_progress_draft_and_numbered_field_choices(): void
    {
        $this->accept();
        $conversation = $this->say('tengo 80 kilos de quinua');
        $draft = $conversation->context['draft'];
        $key = $conversation->context['draft_key'];
        $choices = $conversation->context['choices'];
        foreach (['HOLA', 'MENU', 'ACEPTO', 'VENDER'] as $body) {
            $conversation = $this->say($body);
            $this->assertSame('OFFER_VARIETY', $conversation->state);
            $this->assertSame($draft, $conversation->context['draft']);
            $this->assertSame($key, $conversation->context['draft_key']);
        }
        $conversation = $this->say('1');
        $this->assertSame('OFFER_QUALITY', $conversation->state);
        $this->assertSame($choices[1], $conversation->context['draft']['variety_id']);
        $this->assertSame($key, $conversation->context['draft_key']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_status_and_help_menu_choices_do_not_start_or_publish_offers(): void
    {
        $offer = $this->offer(['producer_id' => $this->producer->id]);
        $this->accept();
        $conversation = $this->say('2');
        $this->assertSame('READY', $conversation->state);
        $this->assertStringContainsString('Oferta #'.$offer->id.':', $this->reply($conversation));
        $conversation = $this->say('3');
        $this->assertTrue($conversation->human_assigned);
        $this->assertStringContainsString('Un coordinador revisará', $this->reply($conversation));
        $count = $conversation->messages()->where('direction', 'outbound')->count();
        $this->say('1');
        $this->assertSame($count, $conversation->messages()->where('direction', 'outbound')->count());
        $conversation = $this->say('BAJA');
        $this->assertSame('declined', $conversation->consent);
        $this->assertSame('OPTED_OUT', $conversation->state);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_pending_verification_cannot_open_offer_menu_or_skip_review(): void
    {
        $this->producer->update(['status' => 'pending_verification']);
        $conversation = $this->accept();
        $this->assertSame('WAITING_IDENTITY_VERIFICATION', $conversation->state);
        $conversation = $this->say('quiero agregar producto');
        $this->assertSame('WAITING_IDENTITY_VERIFICATION', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertStringContainsString('revisión del coordinador', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_bare_ambiguous_quantity_still_requires_a_clear_number(): void
    {
        $this->accept();
        $this->say('VENDER QUINUA');
        $this->say('0');
        $this->say('0');
        $conversation = $this->say('1.000');
        $this->assertSame('OFFER_QUANTITY', $conversation->state);
        $this->assertArrayNotHasKey('quantity', $conversation->context['draft']);
        $this->assertStringContainsString('Cantidad ambigua', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_first_reply_recognizes_the_profile_and_explains_the_options_without_guessing_a_crop(): void
    {
        $this->producer->update(['type' => 'cooperative']);
        $conversation = $this->say('hola');

        $this->assertStringContainsString('Hola, Productora de Prueba.', $this->reply($conversation));
        $this->assertStringContainsString('Tu ficha: Cooperativa.', $this->reply($conversation));
        $this->assertStringContainsString('Todavía no tenemos productos en tu historial', $this->reply($conversation));
        $this->assertStringContainsString('Después de autorizar podrás: 1 Agregar producto', $this->reply($conversation));
        $this->assertSame('pending', $conversation->consent);
        $this->assertDatabaseCount('producer_consents', 0);

        $conversation = $this->say('1');
        $this->assertSame('WAITING_CONSENT', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
    }

    public function test_details_sent_before_consent_are_resumed_once_after_acceptance(): void
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $conversation = $this->say('tengo 80 kilos de quinua');
        $this->assertSame('WAITING_CONSENT', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertStringContainsString('Ya recibí los datos de tu producto', $this->reply($conversation));
        $this->assertDatabaseCount('offers', 0);

        $conversation = $this->say('ACEPTO');
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame($product->id, $conversation->context['draft']['product_id']);
        $this->assertSame('80', $conversation->context['draft']['quantity']);
        $this->assertArrayNotHasKey('pending_offer', $conversation->context);
        $key = $conversation->context['draft_key'];
        $conversation = $this->say('ACEPTO');
        $this->assertSame($key, $conversation->context['draft_key']);
        $this->assertDatabaseCount('producer_consents', 1);
        $this->assertDatabaseCount('offers', 0);
        Http::assertNothingSent();
    }

    public function test_acceptance_can_include_product_and_quantity_in_the_same_reply(): void
    {
        $this->say('hola');
        $conversation = $this->say('ACEPTO, tengo 80 kilos de quinua');

        $this->assertSame('granted', $conversation->consent);
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame('80', $conversation->context['draft']['quantity']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_casual_yes_and_negated_acceptance_do_not_grant_consent(): void
    {
        $this->say('hola');
        foreach (['si no quiero continuar', 'no acepto', 'acepto no gracias'] as $body) {
            $conversation = $this->say($body);
            $this->assertSame('pending', $conversation->consent);
            $this->assertSame('WAITING_CONSENT', $conversation->state);
        }
        $this->assertDatabaseCount('producer_consents', 0);
    }

    public function test_details_received_while_waiting_for_consent_are_kept_but_expire(): void
    {
        $this->say('hola');
        $conversation = $this->say('tengo 80 kilos de quinua');
        $this->assertSame('WAITING_CONSENT', $conversation->state);
        $this->assertSame('80', $conversation->context['pending_offer']['fields']['quantity']);
        $this->travel(8)->days();
        $conversation = $this->say('ACEPTO');
        $this->assertSame('READY', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertArrayNotHasKey('pending_offer', $conversation->context);
    }

    public function test_pending_verification_does_not_resume_offer_details_after_consent(): void
    {
        $this->producer->update(['status' => 'pending_verification']);
        $conversation = $this->say('tengo 80 kilos de quinua');
        $this->assertStringNotContainsString('1 Agregar producto', $this->reply($conversation));
        $conversation = $this->say('ACEPTO');
        $this->assertSame('WAITING_IDENTITY_VERIFICATION', $conversation->state);
        $this->assertArrayNotHasKey('draft', $conversation->context);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_product_selection_keeps_quantity_and_location_from_the_same_message(): void
    {
        $this->accept();
        $this->say('1');
        $conversation = $this->say('tengo 80 kilos de quinua en Puno');
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame('80', $conversation->context['draft']['quantity']);
        $this->assertArrayHasKey('location_id', $conversation->context['draft']);
        $this->say('0');
        $conversation = $this->say('0');
        $this->assertSame('OFFER_FROM', $conversation->state);
    }

    public function test_producer_can_return_from_human_attention_without_losing_a_draft(): void
    {
        $this->accept();
        $conversation = $this->say('tengo 80 kilos de quinua');
        $draft = $conversation->context;
        $conversation = $this->say('AYUDA');
        $this->assertTrue($conversation->human_assigned);
        $this->assertStringContainsString('MENU para volver', $this->reply($conversation));
        $conversation = $this->say('hola');
        $this->assertSame('human_assigned', $conversation->messages()->where('direction', 'inbound')->latest('id')->first()->metadata['ignored_reason']);
        $conversation = $this->say('MENU');
        $this->assertFalse($conversation->human_assigned);
        $this->assertSame('OFFER_VARIETY', $conversation->state);
        $this->assertSame($draft['draft_key'], $conversation->context['draft_key']);
        $this->assertSame($draft['draft'], $conversation->context['draft']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_menu_cannot_resume_a_contact_who_opted_out(): void
    {
        $this->accept();
        $conversation = $this->say('BAJA');
        $count = $conversation->messages()->where('direction', 'outbound')->count();
        $conversation = $this->say('MENU');
        $this->assertSame('OPTED_OUT', $conversation->state);
        $this->assertSame('declined', $conversation->consent);
        $this->assertSame($count, $conversation->messages()->where('direction', 'outbound')->count());
    }

    public function test_admin_can_distinguish_late_arrival_from_waiting_for_consent(): void
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(),
            $this->producer->phone, 'hola', CarbonImmutable::now()->subMinutes(22));
        app(ConversationEngine::class)->process($message->conversation_id);

        $this->actingAs(User::factory()->withRole('admin')->create())
            ->get(route('messaging.show', $message->conversation_id))->assertOk()
            ->assertSee('22 minutos de demora antes de entrar al sistema')
            ->assertSee('Esperando autorización del productor')
            ->assertSee('Recibido en AgroLazo:');
    }
}
