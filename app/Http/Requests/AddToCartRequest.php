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
            'buy_now' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['quantity.*' => 'Please enter how many you want.'];
    }
}
