<?php

namespace App\Http\Requests\Admin;

use App\Services\AggregationService;
use Illuminate\Foundation\Http\FormRequest;

class SaveLotRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('portions')) {
            return;
        }
        $selected = $this->input('selected', []);
        if (! is_array($selected) || count($selected) > 100) {
            $this->merge(['portions' => null]);

            return;
        }
        $portions = [];
        foreach ($selected as $id) {
            if ((! is_string($id) && ! is_int($id)) || ! ctype_digit((string) $id)) {
                $portions[] = ['offer_id' => null];

                continue;
            }
            $portions[] = ['offer_id' => $id, 'quantity' => $this->input('quantities.'.$id), 'offer_version' => $this->input('versions.'.$id), 'agreement_id' => $this->input('agreements.'.$id)];
        }
        $this->merge(['portions' => $portions]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manage-lots') ?? false;
    }

    public function rules(): array
    {
        return AggregationService::rules();
    }
}
