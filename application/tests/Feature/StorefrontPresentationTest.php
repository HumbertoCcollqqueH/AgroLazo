<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductVisual;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductionCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StorefrontPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_contact_is_only_exposed_when_explicitly_configured_and_valid(): void
    {
        $visuals = app(ProductVisual::class);
        config(['storefront.whatsapp_number' => null, 'storefront.contact_email' => null]);
        $this->assertSame(['whatsapp_url' => null, 'contact_email' => null], $visuals->support());

        config(['storefront.whatsapp_number' => '+51 (999) 123-456', 'storefront.contact_email' => 'ventas@example.org']);
        $this->assertStringStartsWith('https://wa.me/51999123456?text=', $visuals->support()['whatsapp_url']);
        $this->assertSame('ventas@example.org', $visuals->support()['contact_email']);

        foreach (['javascript:alert(1)', 'https://wa.me/51999123456', '1234', '000000000', '51999123456?x=1'] as $invalid) {
            config(['storefront.whatsapp_number' => $invalid, 'storefront.contact_email' => 'invalid-address']);
            $this->assertSame(['whatsapp_url' => null, 'contact_email' => null], $visuals->support());
        }
    }

    public function test_catalog_navigation_uses_active_catalog_and_does_not_invent_stock_or_price(): void
    {
        $this->seed([CatalogSeeder::class, ProductionCatalogSeeder::class]);
        Product::query()->where('slug', 'papa')->firstOrFail()->update(['active' => false]);
        ProductCategory::query()->where('slug', 'frutas')->firstOrFail()->update(['active' => false]);

        $this->get(route('marketplace.index'))->assertOk()
            ->assertViewHas('availableLotCount', 0)
            ->assertViewHas('categoryProducts', function ($products): bool {
                $this->assertCount(10, $products);
                $this->assertFalse($products->contains('slug', 'papa'));
                $this->assertFalse($products->contains('slug', 'palta'));
                foreach ($products as $product) {
                    $this->assertSame(0, $product->available_lots_count);
                    $this->assertArrayNotHasKey('unit_price_min', $product->getAttributes());
                }

                return true;
            });
    }

    public function test_product_visuals_are_marked_illustrative_and_unknown_products_use_the_harvest_image(): void
    {
        $visuals = app(ProductVisual::class);
        $this->assertSame('images/marketplace/quinua.webp', $visuals->forProduct('Quinua')['image']);
        $this->assertTrue($visuals->forProduct('Papa')['illustrative']);
        foreach (['Maíz', 'Palta', 'Café', 'Cacao', 'Cañihua', 'Kiwicha', 'Haba', 'Tomate'] as $product) {
            $this->assertNotSame('images/marketplace/hero-harvest.webp', $visuals->forProduct($product)['image']);
        }
        $this->assertSame('images/marketplace/maiz.webp', $visuals->forProduct('Maíz choclo')['image']);
        $this->assertSame('images/marketplace/palta.webp', $visuals->forProduct('Palta')['image']);
        $this->assertSame('images/marketplace/cafe.webp', $visuals->forProduct('Café pergamino')['image']);
        $this->assertSame('images/marketplace/cacao.webp', $visuals->forProduct('Cacao en grano')['image']);
        $this->assertSame('images/marketplace/hero-harvest.webp', $visuals->forProduct('Producto desconocido')['image']);
    }
}
