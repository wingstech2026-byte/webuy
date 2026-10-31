<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/** Chat photos: longest side 1280px, ~70% JPEG, plus a 320px thumbnail. See ImageProcessor. */
class ChatImageProcessor
{
    public function __construct(private ImageProcessor $images) {}

    /** @return array{path: string, thumb: string} paths on the chat disk */
    public function store(UploadedFile $file): array
    {
        $cfg = config('chat.images');

        $stored = $this->images->process(
            $file->getRealPath(),
            $cfg['disk'],
            'chat',
            $cfg['max_side'],
            $cfg['thumb_side'],
            $cfg['quality'],
            $cfg['thumb_quality'],
        );

        return ['path' => $stored['path'], 'thumb' => $stored['thumb']];
    }
}
