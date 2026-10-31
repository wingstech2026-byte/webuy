<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

trait NormalizesPhones
{
    /** Phone numbers are validated and stored as 232XXXXXXXX. */
    protected function normalizePhoneFields(string ...$fields): void
    {
        foreach ($fields as $field) {
            $value = $this->input($field);

            if (filled($value)) {
                $this->merge([$field => PhoneNumber::normalize($value) ?? $value]);
            }
        }
    }

    protected function phoneRule(): string
    {
        return 'regex:/^232[1-9]\d{7}$/';
    }

    protected function phoneMessage(): string
    {
        return 'Please enter a valid Sierra Leone phone number, like 076 123 456.';
    }
}
