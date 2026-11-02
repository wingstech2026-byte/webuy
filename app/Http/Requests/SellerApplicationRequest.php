<?php

namespace App\Http\Requests;

use App\Enums\Operator;
use App\Http\Requests\Concerns\NormalizesPhones;
use App\Http\Requests\Concerns\ValidatesOwnerIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for applying to become a seller and for editing the shop profile. Applying also needs the owner's
 * identity document (ID card or passport), unless it is already on file (sending the application again after a rejection).
 */
class SellerApplicationRequest extends FormRequest
{
    use NormalizesPhones, ValidatesOwnerIdentity;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('payout_phone');
        $this->normalizeOwnerIdNumber();
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
            // Free delivery for orders from this amount (the shop pays the delivery). Empty: no offer.
            'free_delivery_from' => ['nullable', 'decimal:0,2', 'min:1', 'max:99999999'],
            'logo' => $this->imageRules(),
        ] + ($this->isApplication() ? $this->ownerIdentityRules(! $this->user()->seller?->hasOwnerIdentity()) : []);
    }

    private function isApplication(): bool
    {
        return $this->routeIs('sell.store');
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
        ] + $this->imageMessages('logo', 'logo') + $this->ownerIdentityMessages();
    }
}
