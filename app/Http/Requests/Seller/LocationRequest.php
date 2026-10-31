<?php

namespace App\Http\Requests\Seller;

use App\Models\SellerLocation;
use App\Services\GeoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A shop, warehouse or pickup point on the map, with its delivery radius and fees. */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSeller() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(SellerLocation::TYPES))],
            'public_name' => ['required', 'string', 'max:80'],
            'public_address' => ['nullable', 'string', 'max:160'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'town' => ['nullable', 'string', 'max:80'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'is_residence' => ['nullable', 'boolean'],
            'delivery_enabled' => ['nullable', 'boolean'],
            'pickup_enabled' => ['nullable', 'boolean'],
            'pickup_instructions' => ['nullable', 'string', 'max:300'],
            'delivery_radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:300'],
            'base_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'per_km_fee' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'delivery_days' => ['nullable', 'string', 'max:60'],
            'delivery_window' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! app(GeoService::class)->isValid($this->input('latitude'), $this->input('longitude'))) {
                $validator->errors()->add('latitude', 'Set the pin on your shop in Sierra Leone.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function locationData(): array
    {
        $data = $this->validated();

        foreach (['is_residence', 'delivery_enabled', 'pickup_enabled', 'is_active'] as $flag) {
            $data[$flag] = $this->boolean($flag, $flag === 'is_active' || $flag === 'delivery_enabled');
        }

        $data['base_fee'] ??= 0;
        $data['per_km_fee'] ??= 0;

        return $data;
    }
}
