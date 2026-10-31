<?php

namespace App\Http\Requests;

use App\Enums\Operator;
use App\Http\Requests\Concerns\NormalizesPhones;
use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for applying to become a seller and for editing the shop profile. */
class SellerApplicationRequest extends FormRequest
{
    use NormalizesPhones, ValidatesImages;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('payout_phone');
        $this->merge([
            'offers_delivery' => $this->boolean('offers_delivery'),
            'offers_pickup' => $this->boolean('offers_pickup'),
        ]);
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'min:3', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'area' => ['nullable', 'string', 'max:80'],
            'pickup_address' => ['required', 'string', 'max:200'],
            'payout_phone' => ['required', $this->phoneRule()],
            'payout_operator' => ['required', Rule::enum(Operator::class)],
            'business_registration_no' => ['nullable', 'string', 'max:60'],
            'offers_delivery' => ['boolean'],
            'offers_pickup' => ['boolean'],
            'logo' => $this->imageRules(),
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $this->boolean('offers_delivery') && ! $this->boolean('offers_pickup')) {
                $validator->errors()->add('offers_pickup', 'Choose at least one way buyers can get their order: delivery or pickup.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'shop_name.required' => 'Please enter your shop name.',
            'district.required' => 'Please choose your district.',
            'district.in' => 'Please choose your district.',
            'pickup_address.required' => 'Please enter your pickup address.',
            'payout_phone.required' => 'Please enter the mobile money number we should pay you on.',
            'payout_phone.regex' => $this->phoneMessage(),
            'payout_operator.required' => 'Please choose Orange Money or Afrimoney.',
        ] + $this->imageMessages('logo', 'logo');
    }
}
