<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('wibuy.max_cart_quantity')],
            // The chosen option (size, colour...) when the product has variants.
            'variant' => ['nullable', 'integer'],
            'buy_now' => ['nullable', 'boolean'],
            'addons' => ['nullable', 'array', 'max:20'], // extras for a dish
            'addons.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return ['quantity.*' => 'Please enter how many you want.'];
    }
}
