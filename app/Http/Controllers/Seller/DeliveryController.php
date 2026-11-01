<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The seller asks for a WiBuy rider, or cancels before the rider collects. */
class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function request(Request $request, Order $order): RedirectResponse
    {
        try {
            $this->deliveries->request($order, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Rider requested. You get an SMS when a rider takes it.');
    }

    public function cancel(Request $request, DeliveryJob $deliveryJob): RedirectResponse
    {
        try {
            $this->deliveries->cancel($deliveryJob, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Rider request cancelled.');
    }
}
