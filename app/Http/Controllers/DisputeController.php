<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\DisputeRequest;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;

/** "Report a problem" (buyer) and the page where buyer, seller and admin follow a dispute. */
class DisputeController extends Controller
{
    public function create(Order $order)
    {
        $this->authorize('buy', $order);

        if (! in_array($order->status, OrderStatus::disputable(), true)) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['general' => 'You can only report a problem on an order the seller accepted that is not finished yet.']);
        }

        return view('disputes.create', ['order' => $order->load('items', 'seller'), 'reasons' => DisputeRequest::REASONS]);
    }

    public function store(DisputeRequest $request, Order $order, DisputeService $disputes): RedirectResponse
    {
        $this->authorize('buy', $order);

        try {
            $dispute = $disputes->open(
                $order,
                $request->user(),
                DisputeRequest::REASONS[$request->validated('reason')],
                $request->validated('description'),
                $request->file('photos', []),
            );
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('disputes.show', $dispute)
            ->with('status', 'We told the seller. Your money is on hold until the problem is sorted out.');
    }

    public function show(Dispute $dispute)
    {
        $this->authorize('view', $dispute);

        return view('disputes.show', [
            'dispute' => $dispute->load('order.items', 'order.seller', 'buyer', 'seller', 'photos', 'resolver'),
        ]);
    }
}
