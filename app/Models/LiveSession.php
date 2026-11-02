<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop's live selling show. The video is on YouTube or Facebook (the shop streams there from its phone);
 * WiBuy shows it with the products, a pinned product to buy now, and comments. Kept afterwards as a replay.
 */
class LiveSession extends Model
{
    public const SCHEDULED = 'scheduled';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    /** Where the video may come from. */
    public const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch'];

    protected $fillable = ['seller_id', 'title', 'stream_url', 'status', 'starts_at', 'started_at', 'ended_at', 'pinned_product_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'live_session_products');
    }

    public function pinnedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'pinned_product_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LiveComment::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return in_array($parts['scheme'] ?? '', ['https', 'http'], true) && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true);
    }

    /** The address to show the video inside the page (YouTube or Facebook player). */
    public function getEmbedUrlAttribute(): ?string
    {
        $parts = parse_url($this->stream_url);
        $host = strtolower($parts['host'] ?? '');

        if (str_contains($host, 'youtu')) {
            parse_str($parts['query'] ?? '', $query);
            $path = trim($parts['path'] ?? '', '/');
            $id = $query['v'] ?? ($host === 'youtu.be' ? $path : (preg_match('#^(live|shorts|embed)/([\w-]+)#', $path, $m) ? $m[2] : null));

            return $id && preg_match('/^[\w-]{6,20}$/', $id) ? "https://www.youtube.com/embed/{$id}?autoplay=1&playsinline=1" : null;
        }

        return str_contains($host, 'facebook') || $host === 'fb.watch'
            ? 'https://www.facebook.com/plugins/video.php?href='.urlencode($this->stream_url).'&show_text=false'
            : null;
    }
}
