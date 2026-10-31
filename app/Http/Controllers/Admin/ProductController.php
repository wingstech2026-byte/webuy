<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $status = ProductStatus::tryFrom((string) $request->query('status'));
        $term = trim((string) $request->query('q'));

        $items = Product::with('seller', 'images')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->query('featured') === 'deal', fn ($q) => $q->where('is_deal', true))
            ->when($request->query('featured') === 'home', fn ($q) => $q->where('is_featured', true))
            ->when($term !== '', fn ($q) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.products.index', ['items' => $items, 'status' => $status, 'term' => $term]);
    }

    /** Hide a product (it disappears for buyers) or put it back as a draft the seller can publish. */
    public function suspend(Product $product): RedirectResponse
    {
        if ($product->status === ProductStatus::Suspended) {
            $product->update(['status' => ProductStatus::Draft]);

            return back()->with('status', 'Product restored as a draft. The seller can publish it again.');
        }

        $product->update(['status' => ProductStatus::Suspended, 'is_deal' => false, 'is_featured' => false]);

        return back()->with('status', 'Product suspended and hidden from buyers.');
    }

    /** Feature a product in "Deals of the day" or on the home page. */
    public function feature(Request $request, Product $product): RedirectResponse
    {
        $request->validate(['slot' => ['required', 'in:deal,home']]);

        $column = $request->input('slot') === 'deal' ? 'is_deal' : 'is_featured';
        $product->update([$column => ! $product->{$column}]);

        return back()->with('status', $product->{$column} ? 'Product featured.' : 'Product un-featured.');
    }
}
