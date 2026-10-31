<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Slug
{
    /** A URL-safe slug that is unique in the model's "slug" column, e.g. "local-rice-50kg", then "local-rice-50kg-2". */
    public static function unique(string $model, string $text, ?int $ignoreId = null): string
    {
        /** @var class-string<Model> $model */
        $base = Str::limit(Str::slug($text) ?: 'item', 60, '');
        $slug = $base;
        $i = 2;

        while ($model::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
