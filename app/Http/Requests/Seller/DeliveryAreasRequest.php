<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSeller() ?? false;
    }

    public function rules(): array
    {
        $rules = ['fees' => ['nullable', 'array']];

        foreach (config('wibuy.districts') as $district) {
            $rules["fees.{$district}"] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['fees.*.decimal' => 'Enter delivery fees as amounts like 25 or 25.50.'];
    }
}
