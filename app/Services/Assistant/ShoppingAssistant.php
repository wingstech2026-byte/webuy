<?php

namespace App\Services\Assistant;

use App\Exceptions\MarketplaceException;
use App\Models\AssistantLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\User;
use App\Services\RfqService;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * AI shopping assistant (behind ASSISTANT_ENABLED). Understands requests like "I need 50 bags of cement
 * delivered to Bo next week under Le 30,000", searches WiBuy, builds a shortlist and can turn the request
 * into an RFQ. Every request is logged; each person has a daily limit.
 */
class ShoppingAssistant
{
    public function __construct(private AnthropicClient $claude, private RfqService $rfqs) {}

    public static function enabled(): bool
    {
        return (bool) config('wibuy.features.assistant');
    }

    /**
     * @return array{reply: string, understood: array<string, mixed>, shortlist: Collection<int, Product>, rfq_draft: array<string, mixed>, left_today: int}
     *
     * @throws MarketplaceException
     */
    public function ask(User $user, string $message): array
    {
        if (! self::enabled()) {
            throw new MarketplaceException('The shopping assistant is not switched on.');
        }

        $used = AssistantLog::where('user_id', $user->id)->where('created_at', '>=', today())->count();
        $limit = RewardSettings::int('assistant_daily_limit');

        if ($used >= $limit) {
            throw new MarketplaceException("You used the assistant {$limit} times today. Try again tomorrow, or search yourself.");
        }

        try {
            $result = $this->claude->structured($this->system(), $message, $this->schema());
        } catch (RuntimeException $e) {
            AssistantLog::create(['user_id' => $user->id, 'message' => $message, 'status' => 'error', 'understood' => ['error' => $e->getMessage()]]);
            report($e);

            throw new MarketplaceException('The assistant is not available right now. Please search or post a request for quotes yourself.');
        }

        $understood = $result['data'];

        AssistantLog::create([
            'user_id' => $user->id,
            'message' => $message,
            'understood' => $understood,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ]);

        return [
            'reply' => (string) ($understood['reply'] ?? 'Here is what I found.'),
            'understood' => $understood,
            'shortlist' => $this->shortlist($understood),
            'rfq_draft' => $this->draft($understood, $message),
            'left_today' => max(0, $limit - $used - 1),
        ];
    }

    /** Turn the assistant's draft (checked by the person) into a request for quotes. */
    public function createRfq(User $user, array $draft): Rfq
    {
        return $this->rfqs->create($user, $draft, 'assistant');
    }

    /** @return Collection<int, Product> */
    private function shortlist(array $u): Collection
    {
        $query = Product::listed()->with(['seller.deliveryAreas', 'images', 'tiers']);

        foreach (array_filter(preg_split('/\s+/', mb_strtolower((string) ($u['product'] ?? '')))) as $word) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
            $query->whereRaw("lower(products.title) like ? escape '\\'", [$like]);
        }

        if (! empty($u['category']) && $category = Category::where('name', 'like', $u['category'])->orWhere('slug', $u['category'])->first()) {
            $query->whereIn('category_id', $category->selfAndChildIds());
        }

        $quantity = max(1, (int) ($u['quantity'] ?? 1));
        $maxUnit = $this->maxUnitPrice($u, $quantity);
        $district = $u['district'] ?? null;

        return $query->limit(40)->get()
            ->filter(fn (Product $p) => $maxUnit === null || (float) $p->unitPriceFor($quantity) <= $maxUnit)
            ->sortByDesc(fn (Product $p) => [
                $district && $p->seller->deliveryFeeFor($district) !== null ? 1 : 0,
                $p->stock >= $quantity ? 1 : 0,
                $p->seller->reliability_score ?? 60,
                -(float) $p->unitPriceFor($quantity),
            ])
            ->take(5)->values();
    }

    private function maxUnitPrice(array $u, int $quantity): ?float
    {
        if (is_numeric($u['max_unit_price'] ?? null)) {
            return (float) $u['max_unit_price'];
        }

        return is_numeric($u['max_total'] ?? null) ? (float) $u['max_total'] / $quantity : null;
    }

    private function draft(array $u, string $message): array
    {
        $quantity = max(1, (int) ($u['quantity'] ?? 1));
        $maxUnit = $this->maxUnitPrice($u, $quantity);
        $districts = config('wibuy.districts');

        return [
            'title' => mb_substr(trim(($u['quantity'] ?? '').' '.($u['unit'] ?? '').' '.($u['product'] ?? 'Request')), 0, 120),
            'description' => $message,
            'quantity' => $quantity,
            'unit' => mb_substr((string) ($u['unit'] ?? 'piece'), 0, 40) ?: 'piece',
            'district' => in_array($u['district'] ?? null, $districts, true) ? $u['district'] : 'Western Area Urban',
            'target_price' => $maxUnit !== null ? Money::from($maxUnit) : null,
            'needed_by' => $u['needed_by'] ?? null,
        ];
    }

    private function system(): string
    {
        return 'You help shoppers on WiBuy, an online marketplace in Sierra Leone (currency: Leones, written Le). '
            .'Read the shopper\'s request and extract what they want to buy. Prices are in Leones. '
            .'district must be one of: '.implode(', ', config('wibuy.districts')).', or null if not said. '
            .'needed_by is a date (YYYY-MM-DD) if the shopper gives a time such as "next week" (today is '.today()->toDateString().'), else null. '
            .'max_total is the budget for everything; max_unit_price the budget per unit; use null when not said. '
            .'product is a few plain search words (for example "cement"). '
            .'reply is one or two short, friendly sentences in simple English saying what you will look for.';
    }

    private function schema(): array
    {
        $nullable = fn (string $type) => ['type' => [$type, 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['product', 'category', 'quantity', 'unit', 'district', 'max_unit_price', 'max_total', 'needed_by', 'reply'],
            'properties' => [
                'product' => ['type' => 'string'],
                'category' => $nullable('string'),
                'quantity' => $nullable('integer'),
                'unit' => $nullable('string'),
                'district' => $nullable('string'),
                'max_unit_price' => $nullable('number'),
                'max_total' => $nullable('number'),
                'needed_by' => $nullable('string'),
                'reply' => ['type' => 'string'],
            ],
        ];
    }
}
