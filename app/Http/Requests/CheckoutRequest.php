<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Http\Requests\Concerns\NormalizesPhones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    use NormalizesPhones;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('recipient_phone', 'payer_phone');
    }

    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['cart', 'buy'])],
            'product' => ['nullable', 'string', 'max:120'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'variant' => ['nullable', 'integer'],

            'address_id' => ['nullable', 'integer'],
            'recipient_name' => ['required_without:address_id', 'nullable', 'string', 'max:80'],
            'recipient_phone' => ['required_without:address_id', 'nullable', $this->phoneRule()],
            'district' => ['nullable', Rule::in(config('wibuy.districts'))],
            'address' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:200'],

            'methods' => ['required', 'array'],
            'station_id' => ['nullable', 'integer'], // checked by CheckoutService (must be an active station)
            'methods.*' => ['required', Rule::enum(DeliveryMethod::class)],

            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', $this->phoneRule()],
            'coupon_id' => ['nullable', 'integer'],
            'sample' => ['nullable', 'boolean'],
            'payment_plan' => ['nullable', 'in:full,milestones'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Please enter your name.',
            'recipient_phone.required' => 'Please enter a phone number the seller can call.',
            'recipient_phone.regex' => $this->phoneMessage(),
            'methods.required' => 'Choose delivery or pickup for each seller.',
            'methods.*.required' => 'Choose delivery or pickup for each seller.',
            'operator.required' => 'Please choose Orange Money or Afrimoney.',
            'payer_phone.required' => 'Please enter your mobile money number.',
            'payer_phone.regex' => $this->phoneMessage(),
        ];
    }
}
