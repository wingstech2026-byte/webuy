<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Seller;
use App\Support\Money;

class DashboardController extends Controller
{
    public function __invoke()
    {
        // "Sales" = orders that were paid for and not refunded or cancelled.
        $sold = [
            OrderStatus::Paid, OrderStatus::Accepted, OrderStatus::Shipped,
            OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Disputed,
        ];

        $sales = fn ($from) => Order::whereIn('status', $sold)->where('paid_at', '>=', $from);

        return view('admin.dashboard', [
            'salesToday' => Money::from($sales(today())->sum('total')),
            'salesMonth' => Money::from($sales(now()->startOfMonth())->sum('total')),
            'ordersToday' => $sales(today())->count(),
            // Commission is earned when an order completes.
            'commissionMonth' => Money::from(Order::where('status', OrderStatus::Completed)->where('completed_at', '>=', now()->startOfMonth())->sum('commission')),
            'commissionTotal' => Money::from(Order::where('status', OrderStatus::Completed)->sum('commission')),
            'pendingApplications' => Seller::where('status', SellerStatus::Pending)->count(),
            'newSellers' => Seller::where('created_at', '>=', now()->subDays(7))->count(),
            'openDisputes' => Dispute::where('status', DisputeStatus::Open)->count(),
            'pendingPayouts' => Payout::where('status', PayoutStatus::Requested)->count(),
            'pendingPayoutsAmount' => Money::from(Payout::where('status', PayoutStatus::Requested)->sum('amount')),
            'refundsWaiting' => Order::whereIn('status', [OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::Disputed])
                ->whereHas('payments', fn ($q) => $q->where('kind', 'refund')->where('status', 'failed'))->count(),
        ]);
    }
}
