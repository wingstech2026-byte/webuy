<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShopHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isShopOwner() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'holiday_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+3 months'],
            'holiday_message' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['holiday_until.*' => 'Choose a date from today, within 3 months.'];
    }
}
