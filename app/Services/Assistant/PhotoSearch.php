<?php

namespace App\Services\Assistant;

use App\Exceptions\MarketplaceException;
use App\Models\AssistantLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageProcessor;
use App\Support\RewardSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Search by photo (behind PHOTO_SEARCH_ENABLED). The shopper takes or picks a photo of an item; Claude says
 * what the item is and gives plain search words; WiBuy then searches its own live products with them.
 * The photo is shrunk in memory, sent once and never stored. Uses count towards the assistant's daily limit.
 */
class PhotoSearch
{
    public function __construct(private AnthropicClient $claude, private ImageProcessor $images) {}

    public static function enabled(): bool
    {
        return (bool) config('wibuy.features.photo_search');
    }

    /**
     * @return array{understood: array{is_item: bool, name: string, search_words: list<string>, category: ?string, reply: string}, products: Collection<int, Product>, left_today: int}
     *
     * @throws MarketplaceException
     */
    public function search(User $user, UploadedFile $photo): array
    {
        if (! self::enabled()) {
            throw new MarketplaceException('Search by photo is not switched on.');
        }

        $used = AssistantLog::where('user_id', $user->id)->where('created_at', '>=', today())->count();
        $limit = RewardSettings::int('assistant_daily_limit');

        if ($used >= $limit) {
            throw new MarketplaceException("You searched by photo {$limit} times today. Try again tomorrow, or type what you want.");
        }

        try {
            $jpeg = $this->images->shrink($photo->getRealPath(), 1024);
        } catch (RuntimeException) {
            throw new MarketplaceException('That photo could not be read. Please try another one.');
        }

        try {
            $result = $this->claude->structured($this->system(), [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ['type' => 'text', 'text' => 'What item is this? Give search words to find it for sale.'],
            ], $this->schema());
        } catch (RuntimeException $e) {
            AssistantLog::create(['user_id' => $user->id, 'message' => 'Photo search', 'status' => 'error', 'understood' => ['error' => $e->getMessage()]]);
            report($e);

            throw new MarketplaceException('Search by photo is not available right now. Please type what you are looking for.');
        }

        $understood = $this->clean($result['data']);

        AssistantLog::create([
            'user_id' => $user->id,
            'message' => 'Photo search',
            'understood' => $understood,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ]);

        return [
            'understood' => $understood,
            'products' => $understood['is_item'] ? $this->matches($understood['search_words'], $understood['category']) : collect(),
            'left_today' => max(0, $limit - $used - 1),
        ];
    }

    /**
     * Live products that match the words, best first: a word in the title counts more than one in the
     * description, and earlier words (the most important) count more than later ones.
     *
     * @param  list<string>  $searchWords
     * @return Collection<int, Product>
     */
    public function matches(array $searchWords, ?string $category = null, int $limit = 12): Collection
    {
        $words = collect($searchWords)
            ->flatMap(fn (string $phrase) => preg_split('/[^\pL\pN]+/u', mb_strtolower($phrase)))
            ->filter(fn (string $w) => mb_strlen($w) >= 3)->unique()->take(10)->values();

        if ($words->isEmpty()) {
            return collect();
        }

        $like = fn (string $w) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w).'%';
        $categoryIds = $category ? Category::where('name', 'like', $category)->orWhere('slug', $category)->first()?->selfAndChildIds() : null;

        $candidates = Product::listed()->with(['seller', 'images', 'tiers'])
            ->where(function (Builder $q) use ($words, $like) {
                foreach ($words as $w) {
                    $q->orWhereRaw("lower(products.title) like ? escape '\\'", [$like($w)])
                        ->orWhereRaw("lower(products.description) like ? escape '\\'", [$like($w)]);
                }
            })
            ->limit(200)->get();

        $count = $words->count();

        return $candidates
            ->map(function (Product $p) use ($words, $count, $categoryIds) {
                $title = mb_strtolower($p->title);
                $description = mb_strtolower((string) $p->description);
                $titleHits = 0;
                $score = 0;

                foreach ($words as $i => $w) {
                    $weight = $count - $i;

                    if (str_contains($title, $w)) {
                        $titleHits++;
                        $score += 3 * $weight;
                    } elseif (str_contains($description, $w)) {
                        $score += $weight;
                    }
                }

                if ($categoryIds && in_array($p->category_id, $categoryIds, true)) {
                    $score += $count;
                }

                return ['product' => $p, 'score' => $score, 'title_hits' => $titleHits];
            })
            // One stray word in a description is not a match.
            ->filter(fn (array $m) => $m['title_hits'] > 0 || $m['score'] >= 2 * $count)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('product')->values();
    }

    /** @return array{is_item: bool, name: string, search_words: list<string>, category: ?string, reply: string} */
    private function clean(array $data): array
    {
        return [
            'is_item' => (bool) ($data['is_item'] ?? false),
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 120),
            'search_words' => collect($data['search_words'] ?? [])->filter(fn ($w) => is_string($w) && trim($w) !== '')
                ->map(fn (string $w) => mb_substr(trim($w), 0, 40))->take(6)->values()->all(),
            'category' => is_string($data['category'] ?? null) ? $data['category'] : null,
            'reply' => mb_substr(trim((string) ($data['reply'] ?? '')), 0, 300),
        ];
    }

    private function system(): string
    {
        return 'You help shoppers on WiBuy, an online marketplace in Sierra Leone. The shopper sent a photo of something they want to buy. '
            .'Say what the item is so it can be found among products for sale. Describe only the item: never identify, name or describe people in the photo. '
            .'is_item is false when the photo shows no item that could be bought (for example only a person, a landscape or a blank image). '
            .'name is a short plain name for the item, for example "White plastic garden chair". '
            .'search_words are 2 to 6 plain words or short phrases a seller would use in a product title, the most important first; '
            .'include the common general name (for example "chair") and, when useful, the material, type or brand written on it. '
            .'category is one of: '.Category::whereNull('parent_id')->pluck('name')->implode(', ').'; or null when none fits. '
            .'reply is one short, friendly sentence in simple English saying what you see, for example "This looks like a white plastic garden chair."';
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_item', 'name', 'search_words', 'category', 'reply'],
            'properties' => [
                'is_item' => ['type' => 'boolean'],
                'name' => ['type' => 'string'],
                'search_words' => ['type' => 'array', 'items' => ['type' => 'string']],
                'category' => ['type' => ['string', 'null']],
                'reply' => ['type' => 'string'],
            ],
        ];
    }
}
