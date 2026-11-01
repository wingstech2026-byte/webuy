<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The buyer's problem report. */
class DisputeRequest extends FormRequest
{
    use ValidatesImages;

    public const REASONS = [
        'not_received' => 'I did not receive my order',
        'not_as_described' => 'The items are not as described',
        'damaged' => 'The items are damaged or broken',
        'wrong_items' => 'I got the wrong items',
        'fake' => 'The items are fake (not genuine)',
        'missing_items' => 'Some items are missing',
        'other' => 'Something else',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(array_keys(self::REASONS))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => $this->imageRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please choose what went wrong.',
            'description.required' => 'Please describe the problem.',
            'description.min' => 'Please describe the problem in a few words.',
            'photos.max' => 'You can add up to 4 photos.',
        ] + $this->imageMessages('photos.*');
    }
}
