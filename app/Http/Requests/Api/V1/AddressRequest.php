<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesPhones;
use App\Services\GeoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A saved delivery address. The map pin is optional; when given it must be in Sierra Leone. */
class AddressRequest extends FormRequest
{
    use NormalizesPhones;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('recipient_phone');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:80'],
            'recipient_phone' => ['required', $this->phoneRule()],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'town' => ['nullable', 'string', 'max:80'],
            'neighbourhood' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:120'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'directions' => ['nullable', 'string', 'max:300'],
            'latitude' => ['nullable', 'numeric', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'required_with:latitude'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('latitude') && ! app(GeoService::class)->isValid($this->input('latitude'), $this->input('longitude'))) {
                $validator->errors()->add('latitude', 'That map position is not in Sierra Leone. Move the pin or leave it empty.');
            }

            if (! $this->filled('latitude') && ! $this->filled('landmark') && ! $this->filled('street')) {
                $validator->errors()->add('landmark', 'Add a street or a landmark (or set the map pin) so the rider can find you.');
            }
        }];
    }
}
