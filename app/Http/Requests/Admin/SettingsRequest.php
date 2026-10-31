<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
            'min_payout' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'commission_percent.max' => 'The commission cannot be more than 50%.',
            'commission_percent.decimal' => 'Use at most 2 decimals, like 5 or 7.5.',
        ];
    }
}
