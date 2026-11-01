<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOwnerIdentity;
use Illuminate\Foundation\Http\FormRequest;

/** A shop owner who already applied sends (or replaces) their identity document (ID card or passport). */
class OwnerIdentityRequest extends FormRequest
{
    use ValidatesOwnerIdentity;

    public function authorize(): bool
    {
        return $this->user()?->seller !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeOwnerIdNumber();
    }

    public function rules(): array
    {
        return $this->ownerIdentityRules(true);
    }

    public function messages(): array
    {
        return $this->ownerIdentityMessages();
    }
}
