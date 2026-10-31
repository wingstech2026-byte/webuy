<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrialReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:20', 'max:1000'],
            'photos' => ['required', 'array', 'min:1', 'max:4'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('wibuy.images.max_upload_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.required' => 'Free trial reviews need at least one photo of the product.',
            'comment.min' => 'Please write a little more (at least 20 letters) so other buyers can learn from it.',
            'photos.*.mimes' => 'Please choose photos (JPG, PNG or WebP).',
        ];
    }
}
