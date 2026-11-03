<?php

namespace App\Http\Controllers;

use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Services\RecommendationService;
use App\Services\ReviewService;
use App\Services\ShopOfferService;
use App\Support\BrowsingHistory;
use App\Support\Money;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private RecommendationService $recommendations) {}

    public function show(Request $request, Product $product)
    {
        $user = $request->user();

        // Drafts, suspended products and products of unapproved shops are only for their owner and admins.
        if (! $product->isListed() && ! ($user && $user->can('preview', $product))) {
            abort(404);
        }

        $product->load(['seller.logo', 'category.parent', 'images', 'tiers', 'variants']);

        $history = new BrowsingHistory($request->session());

        if ($product->isListed()) {
            $history->viewed($product);

            if ($user) {
                $this->recommendations->recordView($user, $product);
            }
        }

        // Guests who press "Add to cart" log in first and come back here.
        if (! $user) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $url = route('products.show', $product);
        $shareText = sprintf(
            '%s for %s on %s. %s %s',
            $product->title,
            Money::format($product->price),
            config('wibuy.name'),
            config('wibuy.tagline'),
            $url,
        );

        return view('products.show', [
            'product' => $product,
            'comparing' => $history->comparing($product),
            // A live group price for this product: start a group or join one that is filling up.
            'groupOffer' => $groupOffer = GroupBuyCampaign::open()->where('product_id', $product->id)->with('collectionPoint')->latest('id')->first(),
            'openGroups' => $groupOffer ? $groupOffer->groups()->where('status', GroupBuy::OPEN)->where('expires_at', '>', now())->with('leader')->orderBy('expires_at')->limit(5)->get() : collect(),
            'recent' => $history->recent($product, 8),
            'reviews' => $product->reviews()->with('buyer', 'images')->limit(10)->get(),
            'foodScores' => ReviewService::foodScores($product->id),
            'alsoBought' => $this->recommendations->alsoBought($product, 4),
            // The shop's own offers that apply to this product, and bundles with it.
            'shopOffers' => app(ShopOfferService::class)->forProduct($product),
            'questions' => $product->questions()->public()->latest('answered_at')->limit(10)->get(),
            'more' => $product->seller->products()->listed()->where('products.id', '!=', $product->id)
                ->with('images', 'tiers', 'seller')->latest('id')->limit(4)->get(),
            'wishlisted' => $user ? WishlistItem::where('user_id', $user->id)->where('product_id', $product->id)->exists() : false,
            'isOwner' => $user?->isSeller() && $user->seller->id === $product->seller_id,
            'whatsappUrl' => 'https://wa.me/?text='.rawurlencode($shareText),
            'shareUrl' => $url,
        ]);
    }

    /**
     * "Ask the seller". Direct chat is behind CHAT_DIRECT_ENABLED: while it is off this shows a
     * "coming soon" page; once it is on, it opens a private chat with the shop.
     */
    public function ask(Request $request, Product $product)
    {
        abort_unless($product->isListed(), 404);

        $product->load('seller.user');
        $seller = $product->seller;

        if (config('chat.direct_enabled') && $seller->user_id !== $request->user()->id) {
            return redirect()->route('chat.direct', $seller->user);
        }

        return view('products.ask', ['product' => $product, 'seller' => $seller]);
    }
}
