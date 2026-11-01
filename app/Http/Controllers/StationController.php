<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\Station;
use App\Services\StationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The partner's station page: parcels coming from shops, parcels waiting for riders, parcels coming from riders,
 * and parcels waiting for buyers. Every step is found by the order number written on the parcel.
 */
class StationController extends Controller
{
    public function __construct(private StationService $stations) {}

    public function index(Request $request): View
    {
        $station = $this->station($request);
        $orders = fn () => Order::with('seller', 'buyer')->whereIn('status', [OrderStatus::Accepted, OrderStatus::Shipped])->latest('id');

        return view('station.index', [
            'station' => $station,
            'fromShops' => $orders()->where('drop_station_id', $station->id)->where('status', OrderStatus::Accepted)->whereNull('at_drop_station_at')->get(),
            'forRiders' => DeliveryJob::with('order', 'rider')->where('pickup_station_id', $station->id)->whereIn('status', [DeliveryJob::OPEN, DeliveryJob::ASSIGNED])->latest('id')->get(),
            'fromRiders' => DeliveryJob::with('order', 'rider')->where('dropoff_station_id', $station->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->latest('id')->get(),
            'forBuyers' => $orders()->where('collect_station_id', $station->id)->where('delivery_method', DeliveryMethod::Station)
                ->where('status', OrderStatus::Shipped)->whereNotNull('at_collect_station_at')->get(),
        ]);
    }

    /** Find a parcel by the order number written on it, then do the step. */
    public function act(Request $request, string $action): RedirectResponse
    {
        $station = $this->station($request);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'code' => [$action === 'hand-over' ? 'required' : 'nullable', 'digits:4'],
        ], ['number.required' => 'Type the order number written on the parcel.', 'code.*' => "Type the buyer's 4-digit code from the SMS."]);

        $order = Order::where('number', strtoupper(trim($data['number'])))->first();

        if (! $order) {
            return back()->withInput()->withErrors(['number' => 'No order has this number. Check the label on the parcel.']);
        }

        try {
            match ($action) {
                'from-shop' => $this->stations->receiveFromShop($order, $station),
                'from-rider' => $this->stations->receiveFromRider($order, $station),
                'hand-over' => $this->stations->handToBuyer($order, $station, $data['code']),
            };
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', match ($action) {
            'from-shop' => "Order {$order->number} received from the shop.",
            'from-rider' => "Order {$order->number} received from the rider. The buyer got a code by SMS.",
            default => "Order {$order->number} handed to the buyer. Thank you!",
        });
    }

    private function station(Request $request): Station
    {
        $station = Station::where('user_id', $request->user()->id)->first();
        abort_unless($station, 403, 'This page is for WiBuy station partners.');

        return $station;
    }
}
