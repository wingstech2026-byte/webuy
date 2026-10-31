<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A short reason or note, used to reject orders, payouts and seller applications and to ship with a note. */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }
}
