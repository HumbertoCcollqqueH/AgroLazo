<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductVisual
{
    public const FEATURED_PRODUCTS = ['quinua', 'papa', 'canihua', 'oca', 'cebada-grano', 'trigo', 'avena-grano'];

    /** @return array{image: string, alt: string, illustrative: bool} */
    public function forProduct(Product|string $product): array
    {
        $slug = $product instanceof Product ? $product->slug : Str::slug($product);
        $name = $product instanceof Product ? $product->name : $product;
        $image = match (true) {
            $slug === 'quinua' => 'quinua',
            $slug === 'papa' => 'papa',
            str_starts_with($slug, 'maiz') => 'maiz',
            $slug === 'palta' => 'palta',
            str_starts_with($slug, 'cafe') => 'cafe',
            str_starts_with($slug, 'cacao') => 'cacao',
            $slug === 'canihua' => 'canihua',
            $slug === 'oca' => 'oca',
            $slug === 'cebada-grano' => 'cebada',
            $slug === 'trigo' => 'trigo',
            $slug === 'avena-grano' => 'avena',
            $slug === 'kiwicha' => 'kiwicha',
            in_array($slug, ['haba', 'haba-grano-verde'], true) => 'haba',
            $slug === 'tomate' => 'tomate',
            default => 'hero-harvest',
        };

        return [
            'image' => 'images/marketplace/'.$image.'.webp',
            'alt' => $image === 'hero-harvest' ? 'Cosecha agrícola peruana, imagen ilustrativa' : $name.', imagen ilustrativa',
            'illustrative' => true,
        ];
    }

    /** @return array{whatsapp_url: ?string, contact_email: ?string} */
    public function support(): array
    {
        if (config('platform.internal_communication')) {
            return ['whatsapp_url' => null, 'contact_email' => null];
        }
        $number = trim((string) config('storefront.whatsapp_number', ''));
        $digits = preg_match('/\A\+?[0-9\s().-]+\z/', $number) === 1 ? preg_replace('/\D/', '', $number) : '';
        $validNumber = preg_match('/\A[1-9][0-9]{7,14}\z/', $digits) === 1;
        $email = trim((string) config('storefront.contact_email', ''));

        return [
            'whatsapp_url' => $validNumber ? 'https://wa.me/'.$digits.'?text='.rawurlencode('Hola, quisiera información para comprar productos en AgroLazo.') : null,
            'contact_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
        ];
    }
}
