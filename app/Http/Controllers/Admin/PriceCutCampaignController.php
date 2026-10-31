<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PriceCutCampaign;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Products people can "cut the price" of (sellers can add their own too). */
class PriceCutCampaignController extends Controller
{
    public function index()
    {
        return view('admin.rewards.price-cuts', [
            'campaigns' => PriceCutCampaign::with('product')->withCount([
                'cuts', 'cuts as reached_count' => fn ($q) => $q->whereIn('status', ['reached', 'used']),
            ])->latest('id')->paginate(30),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = self::validateCampaign($request);
        $product = Product::where('slug', $data['product'])->first();

        if (! $product) {
            return back()->withInput()->withErrors(['product' => 'No product with that link name (slug).']);
        }

        if ($error = self::checkPrices($data, $product)) {
            return back()->withInput()->withErrors($error);
        }

        PriceCutCampaign::create(self::attributes($data, $product, $request->user()->id));

        return back()->with('status', 'Price cut added.');
    }

    public function toggle(PriceCutCampaign $priceCutCampaign): RedirectResponse
    {
        $priceCutCampaign->update(['is_active' => ! $priceCutCampaign->is_active]);

        return back()->with('status', $priceCutCampaign->is_active ? 'Price cut switched on.' : 'Price cut switched off.');
    }

    /** Shared with the seller's promotions page. */
    public static function validateCampaign(Request $request): array
    {
        return $request->validate([
            'product' => ['required', 'string', 'max:120'],
            'floor_price' => ['required', 'numeric', 'min:0'],
            'hours' => ['required', 'integer', 'min:1', 'max:168'],
            'min_cut' => ['required', 'numeric', 'min:0.01'],
            'max_cut' => ['required', 'numeric', 'gte:min_cut'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    public static function checkPrices(array $data, Product $product): ?array
    {
        return (float) $data['floor_price'] >= (float) $product->price
            ? ['floor_price' => 'The lowest price must be below the normal price ('.money($product->price).').']
            : null;
    }

    public static function attributes(array $data, Product $product, int $userId): array
    {
        return [
            'product_id' => $product->id,
            'created_by_id' => $userId,
            'floor_price' => $data['floor_price'],
            'hours' => $data['hours'],
            'min_cut' => $data['min_cut'],
            'max_cut' => $data['max_cut'],
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
        ];
    }
}
