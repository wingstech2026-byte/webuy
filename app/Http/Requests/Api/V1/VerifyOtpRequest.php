<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesPhones;
use App\Http\Requests\Concerns\ValidatesBuyerDetails;
use Illuminate\Foundation\Http\FormRequest;

/** The app sends the phone again with the code (there is no browser session to remember it). */
class VerifyOtpRequest extends FormRequest
{
    use NormalizesPhones, ValidatesBuyerDetails;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('phone');
    }

    protected function detailsPhone(): ?string
    {
        return $this->input('phone');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', $this->phoneRule()],
            'code' => ['required', 'digits:'.config('wibuy.otp.length')],
            'referral_code' => ['nullable', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ] + $this->buyerDetailsRules($this->needsName(), $this->needsLocation());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => $this->phoneMessage(),
            'code.digits' => 'The code is '.config('wibuy.otp.length').' numbers.',
        ] + $this->buyerDetailsMessages();
    }
}
