<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'seller_rating' => ['nullable', 'integer', 'between:1,5'],
            'seller_comment' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'items.*.comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $any = $this->filled('seller_rating') || collect($this->input('items', []))->contains(fn ($i) => filled($i['rating'] ?? null));

            if (! $any) {
                $validator->errors()->add('seller_rating', 'Please give at least one star rating.');
            }
        }];
    }
}
