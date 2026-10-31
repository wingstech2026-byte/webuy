<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\ReturnRequestForm;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The buyer asks to return items from a delivered order. */
class ReturnController extends Controller
{
    public function create(Request $request, Order $order): View
    {
        abort_unless($order->buyer_id === $request->user()->id, 404);
        abort_unless($order->status === OrderStatus::Delivered && ! $order->returnRequest()->exists(), 404);

        return view('returns.create', ['order' => $order->load('items'), 'reasons' => ReturnRequest::REASONS]);
    }

    public function store(ReturnRequestForm $request, Order $order, ReturnService $returns): RedirectResponse
    {
        try {
            $returns->request($order, $request->user(), $request->quantities(), $request->validated('reason'), $request->validated('description'), $request->file('photos', []));
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', 'Return requested. We told the seller.');
    }
}
