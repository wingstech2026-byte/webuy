<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** A picture attached to a product, shop (logo) or dispute. Resized by ProcessImageJob. */
class Image extends Model
{
    public const DISK = 'public';

    protected $fillable = [
        'imageable_type', 'imageable_id', 'collection', 'original_path', 'path', 'thumb_path',
        'position', 'status', 'user_id',
    ];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk(self::DISK)->url($this->path) : null;
    }

    public function getThumbUrlAttribute(): ?string
    {
        return $this->thumb_path ? Storage::disk(self::DISK)->url($this->thumb_path) : null;
    }
}
