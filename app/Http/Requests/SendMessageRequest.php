<?php

namespace App\Http\Requests;

use App\Enums\ConversationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission to post is checked in the controller so people get a friendly message.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.config('chat.max_length')],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.images.max_upload_kb')],
            'voice' => ['nullable', 'file', 'mimes:'.implode(',', config('chat.voice.mimes')), 'max:'.config('chat.voice.max_upload_kb')],
        ];
    }

    public function messages(): array
    {
        return [
            'body.max' => 'Messages can be up to :max characters.',
            'image.image' => 'Please choose a photo (JPG, PNG or WebP).',
            'image.mimes' => 'Please choose a photo (JPG, PNG or WebP).',
            'image.max' => 'That photo is too big. Please choose one under 8MB.',
            'voice.max' => 'That voice note is too long.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $conversation = $this->route('conversation');
            $body = trim((string) $this->input('body'));

            if ($body === '' && ! $this->hasFile('image') && ! $this->hasFile('voice')) {
                $validator->errors()->add('body', 'Write a message or choose a photo.');

                return;
            }

            if ($this->hasFile('voice')
                && (! config('chat.direct_enabled') || $conversation->type !== ConversationType::Direct)) {
                $validator->errors()->add('voice', 'Voice notes are not available.');

                return;
            }
        }];
    }
}
