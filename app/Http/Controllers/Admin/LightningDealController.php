<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlashDeal;
use App\Models\Product;
use App\Services\FlashDealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Lightning deals: schedule them by hand, or fill a day automatically from "deal" products. */
class LightningDealController extends Controller
{
    public function index()
    {
        return view('admin.rewards.deals', [
            'deals' => FlashDeal::where('ends_at', '>', now()->subDay())->with('product')->withCount('reminders')->orderBy('starts_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:120'],
            'deal_price' => ['required', 'numeric', 'min:0.01'],
            'starts_at' => ['required', 'date'],
            'hours' => ['required', 'integer', 'min:1', 'max:48'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:1000'],
            'quantity_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $product = Product::where('slug', $data['product'])->first();

        if (! $product) {
            return back()->withInput()->withErrors(['product' => 'No product with that link name (slug).']);
        }

        if ((float) $data['deal_price'] >= (float) $product->price) {
            return back()->withInput()->withErrors(['deal_price' => 'The deal price must be lower than the normal price ('.money($product->price).').']);
        }

        $starts = Carbon::parse($data['starts_at']);

        FlashDeal::create([
            'product_id' => $product->id,
            'deal_price' => $data['deal_price'],
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours((int) $data['hours']),
            'max_per_order' => $data['max_per_order'],
            'quantity_limit' => $data['quantity_limit'] ?? null,
        ]);

        return back()->with('status', 'Lightning deal scheduled.');
    }

    public function destroy(FlashDeal $flashDeal): RedirectResponse
    {
        $flashDeal->delete();

        return back()->with('status', 'Lightning deal removed.');
    }

    public function rotate(Request $request, FlashDealService $deals): RedirectResponse
    {
        $day = $request->input('day') === 'today' ? today() : today()->addDay();
        $count = $deals->rotate($day);

        return back()->with('status', "{$count} automatic deal(s) added for {$day->format('j M')}.");
    }
}
