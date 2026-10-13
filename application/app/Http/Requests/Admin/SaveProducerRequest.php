<?php

namespace App\Http\Requests\Admin;

use App\Models\Producer;
use App\Services\ProducerRegistry;
use Illuminate\Foundation\Http\FormRequest;

class SaveProducerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('product_ids') && in_array($this->input('product_ids'), [null, ''], true)) {
            $this->merge(['product_ids' => []]);
        }
    }

    public function authorize(): bool
    {
        $producer = $this->route('producer');

        return $producer instanceof Producer
            ? ($this->user()?->can('update', $producer) ?? false)
            : ($this->user()?->can('create', Producer::class) ?? false);
    }

    public function rules(): array
    {
        return ProducerRegistry::profileRules($this->route('producer') instanceof Producer);
    }

    public function messages(): array
    {
        return [
            'dni.regex' => 'Indica los 8 dígitos del DNI, incluidos los ceros iniciales.',
            'dni.prohibited' => 'El DNI es permanente. No se cambia desde la ficha.',
            'phone.prohibited' => 'Usa el cambio asistido de teléfono para conservar el historial.',
            '*.prohibited' => 'Este dato lo asigna el sistema y no puede enviarse desde el formulario.',
            'latitude.required_with' => 'Indica la latitud junto con la longitud.',
            'longitude.required_with' => 'Indica la longitud junto con la latitud.',
            'latitude.regex' => 'Indica una latitud con punto decimal y hasta 6 decimales.',
            'longitude.regex' => 'Indica una longitud con punto decimal y hasta 6 decimales.',
            'product_ids.array' => 'Selecciona los productos que produce esta persona.',
            'product_ids.*.exists' => 'Selecciona un producto del catálogo.',
            'product_ids.*.distinct' => 'Selecciona cada producto una sola vez.',
        ];
    }
}
