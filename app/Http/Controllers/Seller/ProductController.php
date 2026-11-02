<?php

namespace App\Http\Controllers\Seller;

use App\Enums\ProductCondition;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\ProductRequest;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\BuyerProtectionService;
use App\Services\ProductService;
use App\Services\ProductVideoService;
use App\Services\SponsoredProductService;
use App\Services\VariantService;
use App\Services\WishlistAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ProductService $products, private VariantService $variants, private BuyerProtectionService $protection) {}

    public function index(Request $request)
    {
        $items = $request->user()->seller->products()
            ->with('images', 'category')
            ->when($request->query('q'), fn ($q, $term) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->latest('id')->paginate(20)->withQueryString();

        return view('seller.products.index', ['items' => $items]);
    }

    public function create()
    {
        return view('seller.products.form', $this->formData(new Product(['min_order_quantity' => 1, 'stock' => 1, 'unit' => 'piece', 'status' => 'draft'])));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        try {
            $product = $this->products->save(
                $request->user()->seller,
                $request->productData(),
                $request->tiers(),
                $request->file('images', []),
            );
            $this->variants->sync($product, $request->variants());
            $this->saveVideo($request, $product);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('seller.products.edit', $product)
            ->with('status', $product->status->value === 'active' ? 'Product saved and live. Pictures appear in a moment.' : 'Draft saved.');
    }

    /** @throws MarketplaceException */
    private function saveVideo(ProductRequest $request, Product $product): void
    {
        if ($request->hasFile('video')) {
            app(ProductVideoService::class)->attach($product, $request->file('video'));
        } elseif ($request->boolean('remove_video')) {
            app(ProductVideoService::class)->remove($product);
        }
    }

    /** Sponsor a live product for some days, paid from the shop's balance. */
    public function promote(Request $request, Product $product, SponsoredProductService $sponsored): RedirectResponse
    {
        $this->authorize('manage', $product);

        try {
            $product = $sponsored->promote($product, $request->user(), $request->integer('days'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', "Sponsored until {$product->sponsored_until->format('j M, g:ia')}. It shows first in search and on the home page.");
    }

    public function edit(Product $product)
    {
        $this->authorize('manage', $product);

        return view('seller.products.form', $this->formData($product->load('tiers', 'images', 'variants')));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('manage', $product);

        try {
            $this->products->save(
                $request->user()->seller,
                $request->productData(),
                $request->tiers(),
                $request->file('images', []),
                array_map('intval', $request->input('remove_images', [])),
                $product,
            );
            $this->variants->sync($product, $request->variants());
            $this->saveVideo($request, $product);
            // A lower price: recent buyers get the difference as a coupon (price protection).
            $protected = $this->protection->onPriceChange($product->refresh());
            app(WishlistAlertService::class)->afterChange($product); // "price dropped" / "back in stock"
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('seller.products.edit', $product)->with('status', $protected
            ? "Product saved. The price dropped, so {$protected} recent buyer(s) got the difference as a coupon (taken from your balance)."
            : 'Product saved.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('manage', $product);

        if (OrderItem::where('product_id', $product->id)->exists()) {
            return back()->withErrors(['general' => 'This product has orders, so it cannot be deleted. Set it to "Draft" to hide it instead.']);
        }

        $this->products->delete($product);

        return redirect()->route('seller.products.index')->with('status', 'Product deleted.');
    }

    private function formData(Product $product): array
    {
        return [
            'item' => $product,
            'categories' => Category::whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'conditions' => ProductCondition::cases(),
            'maxImages' => config('wibuy.max_product_images'),
        ];
    }
}
