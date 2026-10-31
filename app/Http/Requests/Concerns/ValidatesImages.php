<?php

namespace App\Http\Requests\Concerns;

trait ValidatesImages
{
    /** Rules for one uploaded picture. SVG is refused on purpose: it can carry scripts. */
    protected function imageRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file', 'image', 'mimes:jpg,jpeg,png,webp',
            'max:'.config('wibuy.images.max_upload_kb'),
        ];
    }

    protected function imageMessages(string $field, string $label = 'photo'): array
    {
        return [
            "{$field}.image" => "Please choose a {$label} (JPG, PNG or WebP).",
            "{$field}.mimes" => "Please choose a {$label} (JPG, PNG or WebP).",
            "{$field}.max" => 'That picture is too big. Please choose one under '.(config('wibuy.images.max_upload_kb') / 1024).'MB.',
        ];
    }
}
