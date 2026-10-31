<?php

namespace App\Http\Requests;

use App\Models\ReturnRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A buyer's return: quantities per order item, a reason, a description and up to 4 photos. */
class ReturnRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->id === $this->route('order')?->buyer_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*' => ['integer', 'min:0', 'max:10000'],
            'reason' => ['required', Rule::in(array_keys(ReturnRequest::REASONS))],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['image', 'max:8192'],
        ];
    }

    /** @return array<int, int> order item id => quantity */
    public function quantities(): array
    {
        return collect($this->validated('items'))->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q])->all();
    }
}
