<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class PayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSeller() ?? false;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999']];
    }

    public function messages(): array
    {
        return ['amount.*' => 'Enter the amount you want, like 500 or 500.50.'];
    }
}
