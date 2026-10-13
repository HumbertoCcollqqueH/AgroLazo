<?php

namespace App\Http\Requests\Admin;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\CatalogRegistry;
use App\Services\UnitConversionService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalogs') ?? false;
    }

    public function rules(): array
    {
        $catalog = $this->route('catalog');
        $definition = app(CatalogRegistry::class)->definition($catalog);
        $record = $this->route('id') ? app(CatalogRegistry::class)->record($catalog, (int) $this->route('id')) : null;
        $rules = ['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']];
        foreach ($definition['fields'] as $name => $field) {
            if ($record && ! ($field['mutable'] ?? false)) {
                $rules[$name] = ['prohibited'];

                continue;
            }
            $rules[$name] = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $rules[$name] = [...$rules[$name], ...match ($name) {
                'category_id' => ['integer', 'exists:product_categories,id'],
                'product_id' => ['integer', 'exists:products,id'],
                'base_unit_id', 'from_unit_id', 'to_unit_id' => ['integer', 'exists:units,id'],
                'parent_id' => ['integer', 'exists:locations,id'],
                'dimension' => ['string', Rule::in(array_keys(Unit::DIMENSIONS))],
                'type' => ['string', Rule::in(array_keys(Location::TYPES))],
                'factor' => ['bail', 'string', 'regex:/^\\d{1,9}(?:\\.\\d{1,9})?$/D', function (string $attribute, mixed $value, Closure $fail): void {
                    if (bccomp($value, '0', 9) <= 0) {
                        $fail('El factor debe ser mayor que cero.');
                    }
                }],
                'valid_from', 'valid_until' => ['date_format:Y-m-d'],
                'code' => ['string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
                'slug' => ['string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
                'context' => ['string', 'max:120'],
                'source', 'description', 'criteria' => ['string', 'max:2000'],
                default => ['string', 'max:150'],
            }];
        }
        if (! $record) {
            $table = (new $definition['model'])->getTable();
            if (isset($rules['slug'])) {
                $rules['slug'][] = Rule::unique($table);
            }
            if (isset($rules['code'])) {
                $rule = Rule::unique($table);
                if (in_array($catalog, ['varieties', 'qualities'], true)) {
                    $rule->where('product_id', $this->input('product_id'));
                }
                $rules['code'][] = $rule;
            }
            if ($catalog === 'conversions') {
                $rules['valid_until'][] = 'after_or_equal:valid_from';
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            '*.prohibited' => 'Este dato es permanente. Desactiva el registro y crea uno nuevo para cambiarlo.',
            'factor.regex' => 'Indica un factor decimal con hasta 9 enteros y 9 decimales.',
            'factor.gt' => 'El factor debe ser mayor que cero.',
            'valid_until.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            '*.date_format' => 'Indica una fecha válida.',
        ];
    }

    /** Validate references again while the catalog write lock is held. */
    public function validateReferences(Model $record): void
    {
        $catalog = $this->route('catalog');
        if ($catalog === 'locations') {
            $duplicate = Location::query()->where('type', $record->type)->where('parent_id', $record->parent_id)
                ->whereRaw('lower(name) = lower(?)', [$record->name])->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists();
            $this->requireValid(! $duplicate, 'name', 'Esa localidad ya existe dentro de la ubicación superior.');
        }
        if ($record->exists && ! $record->active) {
            return;
        }

        if ($catalog === 'products') {
            $category = ProductCategory::query()->find($record->category_id);
            $unit = Unit::query()->find($record->base_unit_id);
            $this->requireValid($category?->active ?? false, 'category_id', 'La categoría no está disponible.');
            $this->requireValid($unit?->active && in_array($unit->dimension, ['mass', 'count'], true), 'base_unit_id', 'Selecciona una unidad base activa de masa o conteo.');
        }
        if (in_array($catalog, ['varieties', 'qualities', 'conversions'], true)) {
            $product = Product::query()->with(['category', 'baseUnit'])->find($record->product_id);
            $this->requireValid($product?->isUsable() ?? false, 'product_id', 'El producto o sus datos de referencia están desactivados.');
        }
        if ($catalog === 'units') {
            $this->requireValid(! isset(UnitConversionService::SI_FACTORS[$record->code]) || $record->dimension === 'mass', 'dimension', 'Gramos, kilogramos y toneladas son unidades de masa.');
        }
        if ($catalog === 'locations') {
            $parentType = Location::PARENT_TYPES[$record->type];
            $parent = $record->parent_id ? Location::query()->find($record->parent_id) : null;
            $this->requireValid($parentType === null ? $parent === null : ($parent?->isUsable() && $parent->type === $parentType),
                'parent_id', 'Selecciona una ubicación activa del nivel inmediatamente superior; los países no tienen padre.');
        }
        if ($catalog === 'conversions') {
            $from = Unit::query()->find($record->from_unit_id);
            $to = Unit::query()->find($record->to_unit_id);
            $this->requireValid($from?->active && $to?->active && $from->id !== $to->id, 'from_unit_id', 'Selecciona unidades activas y diferentes.');
            $this->requireValid($to->id === $product->base_unit_id, 'to_unit_id', 'El destino debe ser la unidad base del producto.');
            $this->requireValid($from->dimension === 'package' || $from->dimension === $to->dimension, 'from_unit_id', 'No se puede convertir entre masa y conteo.');
            $this->requireValid(! isset(UnitConversionService::SI_FACTORS[$from->code], UnitConversionService::SI_FACTORS[$to->code]), 'factor', 'Las equivalencias entre g, kg y t ya están definidas por el SI.');
            if ($record->active) {
                $overlap = UnitConversion::query()->where('active', true)->where('product_id', $record->product_id)
                    ->where('from_unit_id', $record->from_unit_id)->where('to_unit_id', $record->to_unit_id)->where('context', $record->context)
                    ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->where('valid_from', '<=', $record->valid_until?->toDateString() ?? '9999-12-31')
                    ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $record->valid_from->toDateString()))->exists();
                $this->requireValid(! $overlap, 'valid_from', 'Ya existe una conversión activa con ese contexto y vigencias superpuestas.');
            }
        }
    }

    private function requireValid(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
