<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        $own = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:80'],
            // A subcategory's parent must be a top-level category (two levels only).
            'parent_id' => ['nullable', 'exists:categories,id', 'not_in:'.($own ?? 0)],
            'emoji' => ['nullable', 'string', 'max:8'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
