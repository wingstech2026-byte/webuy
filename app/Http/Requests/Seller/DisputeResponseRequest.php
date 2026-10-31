<?php

namespace App\Http\Requests\Seller;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;

class DisputeResponseRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return $this->user()?->isSeller() ?? false;
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:5', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => $this->imageRules(),
        ];
    }

    public function messages(): array
    {
        return ['response.required' => 'Please write your reply.'] + $this->imageMessages('photos.*');
    }
}
