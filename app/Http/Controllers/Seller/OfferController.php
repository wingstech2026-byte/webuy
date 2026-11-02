<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Product;
use App\Models\ShopOffer;
use App\Services\FollowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The shop's own offers (paid by the shop) and bundles, and extras for dishes. */
class OfferController extends Controller
{
    public static function offerRules(int $sellerId): array
    {
        return [
            'kind' => ['required', Rule::in([ShopOffer::SPEND, ShopOffer::CODE, ShopOffer::BOGO])],
            'title' => ['nullable', 'string', 'max:80'],
            'code' => ['required_if:kind,code', 'nullable', 'alpha_num:ascii', 'max:20', Rule::unique('shop_offers')->where('seller_id', $sellerId)],
            'amount' => ['nullable', 'numeric', 'min:1', 'required_without_all:percent,product'],
            'percent' => ['nullable', 'numeric', 'min:1', 'max:90'],
            'max_discount' => ['nullable', 'numeric', 'min:1'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'product' => ['required_if:kind,bogo', 'nullable', 'integer'],
            'buy_quantity' => ['required_if:kind,bogo', 'nullable', 'integer', 'between:1,20'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, mixed> the offer's columns */
    public static function offerAttributes(array $data, int $sellerId): array
    {
        $product = isset($data['product']) ? Product::where('seller_id', $sellerId)->findOrFail($data['product']) : null;
        $isBogo = $data['kind'] === ShopOffer::BOGO;

        return [
            'seller_id' => $sellerId,
            'kind' => $data['kind'],
            'title' => $data['title'] ?? match ($data['kind']) {
                ShopOffer::BOGO => 'Buy more, get one free', ShopOffer::CODE => 'Code '.Str::upper($data['code']), default => 'Shop offer'
            },
            'code' => $data['kind'] === ShopOffer::CODE ? Str::upper($data['code']) : null,
            'amount' => $isBogo || isset($data['percent']) ? null : ($data['amount'] ?? null),
            'percent' => $isBogo ? null : ($data['percent'] ?? null),
            'max_discount' => $isBogo ? null : ($data['max_discount'] ?? null),
            'min_spend' => $isBogo ? 0 : ($data['min_spend'] ?? 0),
            'product_id' => $isBogo ? $product?->id : null,
            'buy_quantity' => $isBogo ? $data['buy_quantity'] : null,
            'max_uses' => $data['max_uses'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $seller = $request->user()->seller;
        $offer = ShopOffer::create(self::offerAttributes($request->validate(self::offerRules($seller->id)), $seller->id));

        // A code is for the people the shop gives it to; other offers are news for followers.
        if ($offer->kind !== ShopOffer::CODE) {
            app(FollowService::class)->tellFollowers($seller, "🏷 {$seller->shop_name}: new offer", $offer->label, route('shops.show', $seller));
        }

        return back()->with('status', 'Offer is live. You pay for it from the sale: buyers see it on your products.');
    }

    public function toggle(Request $request, ShopOffer $offer): RedirectResponse
    {
        abort_unless($offer->seller_id === $request->user()->seller->id, 404);
        $offer->update(['is_active' => ! $offer->is_active]);

        return back()->with('status', $offer->is_active ? 'Offer switched on.' : 'Offer switched off.');
    }

    public static function bundleRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:1'],
            'items' => ['required', 'array', 'min:2', 'max:6'],
            'items.*.product' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    /** @return array{0: Bundle|null, 1: ?string} the bundle, or why it cannot be made */
    public static function makeBundle(array $data, int $sellerId): array
    {
        $items = collect($data['items'])->filter(fn ($row) => ! empty($row['product']))->keyBy('product');
        $products = Product::where('seller_id', $sellerId)->listed()->whereIn('id', $items->keys())->get();

        if ($products->count() < 2 || $products->count() !== $items->count()) {
            return [null, 'Choose at least two of your products.'];
        }

        $normal = $products->sum(fn ($p) => (float) $p->price * (int) ($items[$p->id]['quantity'] ?? 1));

        if ((float) $data['price'] >= $normal) {
            return [null, 'The bundle price must be less than buying them one by one ('.money($normal).').'];
        }

        $bundle = Bundle::create(['seller_id' => $sellerId, 'title' => $data['title'], 'price' => $data['price'], 'is_active' => true]);
        $bundle->products()->attach($products->mapWithKeys(fn ($p) => [$p->id => ['quantity' => (int) ($items[$p->id]['quantity'] ?? 1)]]));

        return [$bundle, null];
    }

    public function storeBundle(Request $request): RedirectResponse
    {
        [, $error] = self::makeBundle($request->validate(self::bundleRules()), $request->user()->seller->id);

        return $error ? back()->withInput()->withErrors(['bundle' => $error]) : back()->with('status', 'Bundle is live on your products.');
    }

    public function toggleBundle(Request $request, Bundle $bundle): RedirectResponse
    {
        abort_unless($bundle->seller_id === $request->user()->seller->id, 404);
        $bundle->update(['is_active' => ! $bundle->is_active]);

        return back()->with('status', $bundle->is_active ? 'Bundle switched on.' : 'Bundle switched off.');
    }

    /** Extras for a dish: the rows on the form replace the list (removed ones stop being offered). */
    public function addons(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('manage', $product);
        $rows = $request->validate([
            'addons' => ['nullable', 'array', 'max:15'],
            'addons.*.id' => ['nullable', 'integer'],
            'addons.*.name' => ['nullable', 'string', 'max:60'],
            'addons.*.price' => ['nullable', 'numeric', 'min:0', 'required_with:addons.*.name'],
        ])['addons'] ?? [];

        self::syncAddons($product, $rows);

        return back()->with('status', 'Extras saved.');
    }

    public static function syncAddons(Product $product, array $rows): void
    {
        $kept = [];

        foreach (array_values(array_filter($rows, fn ($row) => filled($row['name'] ?? null))) as $position => $row) {
            $addon = isset($row['id']) ? $product->addons()->find($row['id']) : null;
            $addon ??= $product->addons()->make();
            $addon->fill(['name' => $row['name'], 'price' => $row['price'], 'is_active' => true, 'position' => $position])->save();
            $kept[] = $addon->id;
        }

        $product->addons()->whereNotIn('id', $kept)->update(['is_active' => false]);
    }
}
