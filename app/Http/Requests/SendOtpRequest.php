<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesPhones;
use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
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

    public function rules(): array
    {
        return ['phone' => ['required', $this->phoneRule()]];
    }

    public function messages(): array
    {
        return ['phone.regex' => $this->phoneMessage()];
    }
}
