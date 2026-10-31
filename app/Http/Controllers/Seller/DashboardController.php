<?php

namespace App\Http\Controllers\Seller;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LedgerService $ledger)
    {
        $seller = $request->user()->seller;

        $counts = Order::where('seller_id', $seller->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('seller.dashboard', [
            'seller' => $seller,
            'counts' => [
                'new' => (int) ($counts[OrderStatus::Paid->value] ?? 0),
                'accepted' => (int) ($counts[OrderStatus::Accepted->value] ?? 0),
                'shipped' => (int) ($counts[OrderStatus::Shipped->value] ?? 0),
                'delivered' => (int) ($counts[OrderStatus::Delivered->value] ?? 0),
                'disputed' => (int) ($counts[OrderStatus::Disputed->value] ?? 0),
            ],
            'money' => $ledger->summary($seller),
            'newOrders' => Order::where('seller_id', $seller->id)->where('status', OrderStatus::Paid)
                ->with('items')->orderBy('accept_by')->limit(5)->get(),
            'lowStock' => $seller->products()->where('stock', '<=', 5)->where('status', '!=', 'suspended')->orderBy('stock')->limit(5)->get(),
            'openDisputes' => Dispute::where('seller_id', $seller->id)->where('status', DisputeStatus::Open)->with('order')->latest('id')->limit(5)->get(),
            'productCount' => $seller->products()->count(),
        ]);
    }
}
