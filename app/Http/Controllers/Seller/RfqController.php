<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Services\RfqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Buyers' requests for quotation, and the seller's quotes (the price is locked for a set number of days). */
class RfqController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        return view('seller.rfqs', [
            'rfqs' => Rfq::open()->where('buyer_id', '!=', $seller->user_id)->with('category')->withCount('quotes')->latest('id')->paginate(20),
            'mine' => RfqQuote::where('seller_id', $seller->id)->with('rfq', 'product')->latest('id')->limit(30)->get(),
            'products' => $seller->products()->listed()->orderBy('title')->get(['id', 'title', 'price']),
            'quoted' => RfqQuote::where('seller_id', $seller->id)->pluck('rfq_id')->all(),
        ]);
    }

    public function quote(Request $request, Rfq $rfq, RfqService $rfqs): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'unit_price' => ['required', 'numeric', 'min:0.01'],
            'quantity' => ['required', 'integer', 'min:1'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $seller = $request->user()->seller;
        $product = $seller->products()->find($data['product_id']);

        try {
            abort_unless($product, 404);
            $rfqs->quote($seller, $rfq, $product, (string) $data['unit_price'], (int) $data['quantity'], (int) $data['lead_time_days'], $data['note'] ?? null);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Quote sent. The buyer sees it next to other quotes.');
    }
}
