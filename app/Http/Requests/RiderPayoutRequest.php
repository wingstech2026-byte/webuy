<?php

namespace App\Http\Requests;

use App\Enums\Operator;
use App\Http\Requests\Concerns\NormalizesPhones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A rider withdrawing delivery fees to Orange Money or Afrimoney. */
class RiderPayoutRequest extends FormRequest
{
    use NormalizesPhones;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('phone');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999'],
            'operator' => ['required', Rule::enum(Operator::class)],
            'phone' => ['required', $this->phoneRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.*' => 'Enter the amount you want, like 500 or 500.50.',
            'operator.*' => 'Please choose Orange Money or Afrimoney.',
            'phone.required' => 'Please enter the mobile money number to pay.',
            'phone.regex' => $this->phoneMessage(),
        ];
    }
}
