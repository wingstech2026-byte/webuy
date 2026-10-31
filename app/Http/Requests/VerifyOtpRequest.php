<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** New people (and people who never gave a name) must give their name. */
    public function needsName(): bool
    {
        return ! User::where('phone', $this->session()->get('otp_phone'))->whereNotNull('name')->exists();
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:'.config('wibuy.otp.length')],
            'name' => [$this->needsName() ? 'required' : 'nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'The code is '.config('wibuy.otp.length').' numbers.',
            'name.required' => 'Please tell us your name.',
        ];
    }
}
