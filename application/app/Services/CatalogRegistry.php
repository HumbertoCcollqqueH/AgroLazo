<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Database\Eloquent\Model;

class CatalogRegistry
{
    public function definitions(): array
    {
        $name = ['label' => 'Nombre', 'type' => 'text', 'required' => true, 'mutable' => true];
        $slug = ['label' => 'Identificador', 'type' => 'text', 'required' => true, 'hint' => 'Usa minúsculas, números y guiones; por ejemplo, granos-andinos.'];
        $code = ['label' => 'Código', 'type' => 'text', 'required' => true, 'hint' => 'Identificador estable, sin espacios.'];
        $product = ['label' => 'Producto', 'type' => 'select', 'options' => 'products', 'required' => true];

        return [
            'products' => [
                'model' => Product::class, 'title' => 'Productos', 'singular' => 'producto',
                'description' => 'Define qué productos comercializar y en qué unidad registrar su cantidad.',
                'relations' => ['category', 'baseUnit'],
                'fields' => [
                    'name' => $name,
                    'slug' => $slug,
                    'category_id' => ['label' => 'Categoría', 'type' => 'select', 'options' => 'categories', 'required' => true],
                    'base_unit_id' => ['label' => 'Unidad base', 'type' => 'select', 'options' => 'baseUnits', 'required' => true, 'hint' => 'La unidad base es de masa o conteo. Un saco es una presentación, no una unidad base.'],
                ],
            ],
            'categories' => [
                'model' => ProductCategory::class, 'title' => 'Categorías', 'singular' => 'categoría',
                'description' => 'Organiza los productos en grupos que puedan crecer con AgroLazo.',
                'relations' => [], 'fields' => ['name' => $name, 'slug' => $slug],
            ],
            'varieties' => [
                'model' => ProductVariety::class, 'title' => 'Variedades', 'singular' => 'variedad',
                'description' => 'Cada variedad pertenece a un producto concreto. Sin especificar es una opción explícita.',
                'relations' => ['product'], 'fields' => ['name' => $name, 'code' => $code, 'product_id' => $product],
            ],
            'qualities' => [
                'model' => QualityGrade::class, 'title' => 'Calidades', 'singular' => 'calidad',
                'description' => 'Documenta los criterios que el coordinador comprobará. Crear una calidad no acredita una certificación.',
                'relations' => ['product'],
                'fields' => [
                    'name' => $name, 'code' => $code, 'product_id' => $product,
                    'description' => ['label' => 'Descripción', 'type' => 'textarea', 'mutable' => true],
                    'criteria' => ['label' => 'Criterios de revisión', 'type' => 'textarea', 'mutable' => true],
                ],
            ],
            'units' => [
                'model' => Unit::class, 'title' => 'Unidades', 'singular' => 'unidad',
                'description' => 'Distingue masa, conteo y presentación. Gramo, kilogramo y tonelada usan equivalencias SI.',
                'relations' => [],
                'fields' => [
                    'name' => $name, 'code' => $code,
                    'dimension' => ['label' => 'Dimensión', 'type' => 'select', 'options' => 'dimensions', 'required' => true],
                ],
            ],
            'locations' => [
                'model' => Location::class, 'title' => 'Localidades', 'singular' => 'localidad',
                'description' => 'Organiza país, departamento, provincia, distrito y comunidad. Un nombre no sustituye la verificación geográfica.',
                'relations' => ['parent'],
                'fields' => [
                    'name' => $name,
                    'type' => ['label' => 'Tipo', 'type' => 'select', 'options' => 'locationTypes', 'required' => true],
                    'parent_id' => ['label' => 'Ubicación superior', 'type' => 'select', 'options' => 'parentLocations', 'hint' => 'Obligatoria salvo para países. Respeta el nivel inmediatamente superior.'],
                    'code' => ['label' => 'Código geográfico (opcional)', 'type' => 'text', 'hint' => 'Usa un código solo si lo conoces y verificaste. No se genera un UBIGEO automáticamente.'],
                ],
            ],
            'conversions' => [
                'model' => UnitConversion::class, 'title' => 'Conversiones', 'singular' => 'conversión',
                'description' => 'Aprueba equivalencias con producto, contexto, evidencia y vigencia. Un saco nunca tiene un peso universal.',
                'relations' => ['product', 'fromUnit', 'toUnit'],
                'fields' => [
                    'product_id' => $product,
                    'from_unit_id' => ['label' => 'Unidad de origen', 'type' => 'select', 'options' => 'allUnits', 'required' => true],
                    'to_unit_id' => ['label' => 'Unidad de destino', 'type' => 'select', 'options' => 'baseUnits', 'required' => true, 'hint' => 'Debe coincidir con la unidad base del producto.'],
                    'factor' => ['label' => 'Factor de conversión', 'type' => 'text', 'required' => true, 'hint' => 'Cantidad de unidades base por unidad de origen. Hasta 9 decimales, con punto.'],
                    'context' => ['label' => 'Contexto o presentación', 'type' => 'text', 'required' => true, 'hint' => 'Identifica la presentación acordada, por ejemplo saco-papa-50kg. Debe coincidir al convertir.'],
                    'source' => ['label' => 'Fuente o evidencia del factor', 'type' => 'textarea', 'required' => true, 'hint' => 'Describe el pesaje, documento o acuerdo que permite usar este factor.'],
                    'valid_from' => ['label' => 'Válida desde', 'type' => 'date', 'required' => true],
                    'valid_until' => ['label' => 'Válida hasta (opcional)', 'type' => 'date'],
                ],
            ],
        ];
    }

    public function definition(string $catalog): array
    {
        return $this->definitions()[$catalog] ?? abort(404);
    }

    public function record(string $catalog, int $id): Model
    {
        $model = $this->definition($catalog)['model'];

        return $model::query()->findOrFail($id);
    }

    public function options(string $source, mixed $current = null): array
    {
        if ($source === 'dimensions') {
            return Unit::DIMENSIONS;
        }

        if ($source === 'locationTypes') {
            return Location::TYPES;
        }

        $class = match ($source) {
            'categories' => ProductCategory::class,
            'products' => Product::class,
            'baseUnits', 'allUnits' => Unit::class,
            'parentLocations' => Location::class,
        };

        $query = $class::query()->where(fn ($query) => $query->where('active', true)->when($current, fn ($query) => $query->orWhere('id', $current)));

        if ($source === 'baseUnits') {
            $query->whereIn('dimension', ['mass', 'count']);
        }

        if ($source === 'products') {
            $query->with(['category', 'baseUnit']);
        }

        if ($source === 'parentLocations') {
            $query->with('parent');
        }

        return $query->orderBy('name')->get()
            ->filter(fn (Model $record): bool => ! method_exists($record, 'isUsable') || $record->getKey() == $current || $record->isUsable())
            ->mapWithKeys(fn (Model $record): array => [$record->getKey() => match (true) {
                $record instanceof Unit => $record->name.' ('.$record->code.')',
                $record instanceof Location => $record->name.' · '.Location::TYPES[$record->type].($record->parent ? ' / '.$record->parent->name : ''),
                default => $record->name,
            }])->all();
    }

    public function summary(string $catalog, Model $record): string
    {
        return match ($catalog) {
            'products' => $record->category->name.' · Unidad base: '.$record->baseUnit->code,
            'categories' => $record->slug,
            'varieties', 'qualities' => $record->product->name.' · '.$record->code,
            'units' => $record->code.' · '.Unit::DIMENSIONS[$record->dimension],
            'locations' => Location::TYPES[$record->type].($record->parent ? ' · '.$record->parent->name : ''),
            'conversions' => '1 '.$record->fromUnit->code.' = '.$record->factor.' '.$record->toUnit->code.' · '.$record->context,
        };
    }
}
