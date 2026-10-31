<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\BrowsingHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Compare up to 3 products side by side, and the visitor's recently viewed products. */
class CompareController extends Controller
{
    public function index(Request $request): View
    {
        $history = new BrowsingHistory($request->session());

        return view('compare', ['products' => $history->compared(), 'recent' => $history->recent()]);
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->isListed(), 404);
        $history = new BrowsingHistory($request->session());
        $was = $history->comparing($product);

        if (! $history->toggleCompare($product)) {
            return back()->withErrors(['general' => 'You can compare up to '.BrowsingHistory::COMPARE_MAX.' products. Remove one first.']);
        }

        return back()->with('status', $was ? 'Removed from compare.' : 'Added to compare. Open "Compare" to see them side by side.');
    }

    public function clear(Request $request): RedirectResponse
    {
        (new BrowsingHistory($request->session()))->clearCompare();

        return redirect()->route('compare.index');
    }
}
