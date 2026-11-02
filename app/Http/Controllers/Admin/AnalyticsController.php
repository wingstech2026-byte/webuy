<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\User;
use App\Support\Money;

/** Sales over the last 30 days: per day, best categories and shops, and orders waiting too long. */
class AnalyticsController extends Controller
{
    /** Paid and not refunded or cancelled (as on the dashboard). */
    private const SOLD = [OrderStatus::Paid, OrderStatus::Accepted, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Disputed];

    public function __invoke()
    {
        $from = today()->subDays(29);
        $sold = fn () => Order::whereIn('orders.status', self::SOLD)->where('orders.paid_at', '>=', $from);

        $perDay = $sold()->selectRaw('DATE(paid_at) as day, count(*) as orders, sum(total) as sales')->groupBy('day')->get()->keyBy('day');
        $days = collect(range(0, 29))->map(function (int $i) use ($from, $perDay) {
            $day = $from->copy()->addDays($i)->toDateString();

            return ['day' => $day, 'orders' => (int) ($perDay[$day]->orders ?? 0), 'sales' => Money::from($perDay[$day]->sales ?? 0)];
        });

        $orders = $days->sum('orders');
        $sales = Money::from($sold()->sum('total'));

        return view('admin.analytics', [
            'days' => $days,
            'maxSales' => max(1, (float) $days->max(fn ($d) => (float) $d['sales'])),
            'sales' => $sales,
            'orders' => $orders,
            'averageOrder' => $orders ? Money::from(bcdiv($sales, (string) $orders, 2)) : Money::from(0),
            'commission' => Money::from(Order::where('status', OrderStatus::Completed)->where('completed_at', '>=', $from)->sum('commission')),
            'newBuyers' => User::where('created_at', '>=', $from)->count(),
            'newShops' => Seller::where('created_at', '>=', $from)->count(),
            'topCategories' => OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->whereIn('orders.status', self::SOLD)->where('orders.paid_at', '>=', $from)
                ->selectRaw('categories.name as name, sum(order_items.line_total) as sales')
                ->groupBy('categories.name')->orderByDesc('sales')->limit(8)->get(),
            'topShops' => $sold()->join('sellers', 'sellers.id', '=', 'orders.seller_id')
                ->selectRaw('sellers.shop_name as name, count(*) as orders, sum(orders.total) as sales')
                ->groupBy('sellers.shop_name')->orderByDesc('sales')->limit(8)->get(),
            // Waiting too long: paid but not accepted for a day, accepted but not sent for 3 days.
            'notAccepted' => Order::with('seller')->where('status', OrderStatus::Paid)->where('paid_at', '<', now()->subDay())->oldest('paid_at')->limit(20)->get(),
            'notShipped' => Order::with('seller')->where('status', OrderStatus::Accepted)->where('accepted_at', '<', now()->subDays(3))->oldest('accepted_at')->limit(20)->get(),
        ]);
    }
}
