<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBuyerDetails;
use Illuminate\Foundation\Http\FormRequest;

/** The SMS code, plus the name and location of new people (and of accounts that miss them). */
class VerifyOtpRequest extends FormRequest
{
    use ValidatesBuyerDetails;

    public function authorize(): bool
    {
        return true;
    }

    protected function detailsPhone(): ?string
    {
        return $this->session()->get('otp_phone');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:'.config('wibuy.otp.length')],
        ] + $this->buyerDetailsRules($this->needsName(), $this->needsLocation());
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'The code is '.config('wibuy.otp.length').' numbers.',
        ] + $this->buyerDetailsMessages();
    }
}
