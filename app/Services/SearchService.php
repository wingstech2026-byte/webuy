<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SearchService
{
    public const SORTS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'rating' => 'Best rated',
        'reliable' => 'Most reliable sellers',
    ];

    /**
     * Search live products.
     *
     * @param  array{q?: ?string, category?: ?string, min_price?: mixed, max_price?: mixed, district?: ?string, verified?: mixed, sort?: ?string, seller?: ?int}  $filters
     */
    public function search(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $query = Product::listed()->with(['seller', 'images', 'tiers']);

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            foreach (preg_split('/\s+/', mb_strtolower($q)) as $word) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';

                $query->where(fn (Builder $w) => $w
                    ->whereRaw("lower(products.title) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(products.description) like ? escape '\\'", [$like]));
            }
        }

        if (! empty($filters['category']) && $category = Category::where('slug', $filters['category'])->first()) {
            $query->whereIn('products.category_id', $category->selfAndChildIds());
        }

        if (is_numeric($filters['min_price'] ?? null)) {
            $query->where('products.price', '>=', $filters['min_price']);
        }

        if (is_numeric($filters['max_price'] ?? null)) {
            $query->where('products.price', '<=', $filters['max_price']);
        }

        if (! empty($filters['district'])) {
            $query->whereHas('seller', fn (Builder $s) => $s->where('district', $filters['district']));
        }

        if (filter_var($filters['verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereHas('seller', fn (Builder $s) => $s->where('is_verified', true));
        }

        // Near a point: only shops with a location within the radius (coordinates, not town names).
        if (isset($filters['near_lat'], $filters['near_lng']) && app(GeoService::class)->isValid($filters['near_lat'], $filters['near_lng'])) {
            $near = app(GeoService::class)->nearbySellers((float) $filters['near_lat'], (float) $filters['near_lng'], (float) ($filters['within_km'] ?? 30));
            $ids = $near->pluck('seller.id')->all();
            $query->whereIn('products.seller_id', $ids ?: [0]);

            if (($filters['sort'] ?? null) === 'nearest' && $ids) {
                $cases = collect($ids)->map(fn ($id, $i) => 'when '.(int) $id.' then '.$i)->implode(' ');
                $query->orderByRaw("case products.seller_id {$cases} else 9999 end");
                $filters['sort'] = 'nearest';
            }
        }

        if (! empty($filters['seller'])) {
            $query->where('products.seller_id', $filters['seller']);
        }

        // A text search ranks sellers who deliver on time first (delivery reliability score; new shops count as 60).
        $reliability = '(select coalesce(sellers.reliability_score, 60) from sellers where sellers.id = products.seller_id)';
        $sort = $filters['sort'] ?? ($q ? 'reliable' : 'newest');

        match ($sort) {
            'reliable' => $query->orderByRaw("{$reliability} desc")->orderByDesc('products.id'),
            'nearest' => $query->orderByDesc('products.id'),
            'price_asc' => $query->orderBy('products.price')->orderByDesc('products.id'),
            'price_desc' => $query->orderByDesc('products.price')->orderByDesc('products.id'),
            'rating' => $query->orderByDesc('products.rating_avg')->orderByDesc('products.rating_count')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.id'),
        };

        return $query->paginate($perPage ?? config('wibuy.per_page'))->withQueryString();
    }

    /** @return Collection<int, Product> */
    public function deals(int $limit = 8): Collection
    {
        return Product::listed()->where('is_deal', true)->with(['seller', 'images', 'tiers'])->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, Product> */
    public function featured(int $limit = 8): Collection
    {
        return Product::listed()->where('is_featured', true)->with(['seller', 'images', 'tiers'])->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, Product> */
    public function newArrivals(int $limit = 12): Collection
    {
        return Product::listed()->with(['seller', 'images', 'tiers'])->latest('id')->limit($limit)->get();
    }

    /** Best rated approved shops, then shops with the most orders. @return Collection<int, Seller> */
    public function topSellers(int $limit = 8): Collection
    {
        return Seller::approved()->with('logo')
            ->withCount(['products' => fn (Builder $q) => $q->where('status', 'active')])
            ->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('id')
            ->limit($limit)->get();
    }

    /** Top-level categories with their subcategories. @return Collection<int, Category> */
    public function categoryTree(): Collection
    {
        return Category::whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get();
    }
}
