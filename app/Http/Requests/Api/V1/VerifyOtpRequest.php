<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesPhones;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** The app sends the phone again with the code (there is no browser session to remember it). */
class VerifyOtpRequest extends FormRequest
{
    use NormalizesPhones;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('phone');
    }

    /** New people (and people who never gave a name) must give their name. */
    public function needsName(): bool
    {
        return ! User::where('phone', $this->input('phone'))->whereNotNull('name')->exists();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', $this->phoneRule()],
            'code' => ['required', 'digits:'.config('wibuy.otp.length')],
            'name' => [$this->needsName() ? 'required' : 'nullable', 'string', 'max:80'],
            'referral_code' => ['nullable', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => $this->phoneMessage(),
            'code.digits' => 'The code is '.config('wibuy.otp.length').' numbers.',
            'name.required' => 'Please tell us your name.',
        ];
    }
}
