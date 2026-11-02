<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A seller's sales report: totals for a period, sales week by week, best sellers and products running low.
 * A sale counts from the moment the buyer paid; refunded, cancelled and rejected orders do not count.
 */
class SellerReportService
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    public const WEEKS = 8;

    public const LOW_STOCK = 5;

    /**
     * @return array{
     *     days: int,
     *     totals: array{orders: int, sales: string, kept: string, average_order: string, items_sold: int, buyers: int, repeat_buyers: int, lost_orders: int},
     *     previous_sales: string,
     *     weeks: list<array{week_start: string, label: string, sales: string, orders: int}>,
     *     best_sellers: list<array{product_id: int, title: string, quantity: int, sales: string}>,
     *     low_stock: list<array{id: int, slug: string, title: string, stock: int, unit: string, status: string}>
     * }
     */
    public function report(Seller $seller, int $days = 30): array
    {
        $days = array_key_exists($days, self::PERIODS) ? $days : 30;
        $now = CarbonImmutable::now();
        $from = $now->subDays($days);

        $orders = $this->sold($seller)->where('paid_at', '>=', $from)->get(['id', 'buyer_id', 'subtotal', 'commission']);
        $sales = $orders->reduce(fn (string $sum, Order $o) => Money::add($sum, $o->subtotal), '0.00');
        $commission = $orders->reduce(fn (string $sum, Order $o) => Money::add($sum, $o->commission), '0.00');
        $perBuyer = $orders->countBy('buyer_id');

        return [
            'days' => $days,
            'totals' => [
                'orders' => $orders->count(),
                'sales' => $sales,
                'kept' => Money::sub($sales, $commission),
                'average_order' => $orders->isEmpty() ? '0.00' : bcdiv($sales, (string) $orders->count(), 2),
                'items_sold' => (int) OrderItem::whereIn('order_id', $orders->pluck('id'))->sum('quantity'),
                'buyers' => $perBuyer->count(),
                'repeat_buyers' => $perBuyer->filter(fn (int $n) => $n > 1)->count(),
                'lost_orders' => Order::where('seller_id', $seller->id)->where('paid_at', '>=', $from)
                    ->whereIn('status', [OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::Refunded])->count(),
            ],
            'previous_sales' => Money::from($this->sold($seller)->whereBetween('paid_at', [$from->subDays($days), $from])->sum('subtotal')),
            'weeks' => $this->weeks($seller, $now),
            'best_sellers' => $this->bestSellers($seller, $from),
            // Per product: who looked, who put it in the cart, who bought (the biggest audiences first).
            'funnel' => $this->funnel($seller, $from),
            // When buyers pay: orders per hour of the day.
            'hours' => $this->hours($seller, $from),
            // "Rice runs out in about 4 days", from the last 14 days of sales.
            'forecast' => $this->forecast($seller, $now),
            'low_stock' => $seller->products()->where('status', ProductStatus::Active)->where('stock', '<=', self::LOW_STOCK)
                ->orderBy('stock')->orderBy('title')->limit(10)->get(['id', 'slug', 'title', 'stock', 'unit', 'status'])
                ->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'title' => $p->title, 'stock' => (int) $p->stock, 'unit' => $p->unit, 'status' => $p->status->value])
                ->all(),
        ];
    }

    /** @return list<array{product_id: int, title: string, views: int, carts: int, orders: int, conversion: ?float}> */
    private function funnel(Seller $seller, CarbonImmutable $from): array
    {
        $ids = $seller->products()->pluck('id');
        $views = DB::table('product_views')->whereIn('product_id', $ids)->where('viewed_at', '>=', $from)->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $carts = DB::table('cart_items')->whereIn('product_id', $ids)->where('created_at', '>=', $from)->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $orders = OrderItem::whereIn('order_id', $this->sold($seller)->where('paid_at', '>=', $from)->select('id'))
            ->selectRaw('product_id, count(distinct order_id) as n')->groupBy('product_id')->pluck('n', 'product_id');

        return $seller->products()->whereIn('id', $views->keys()->merge($carts->keys())->merge($orders->keys())->unique())->get(['id', 'title'])
            ->map(fn ($p) => [
                'product_id' => $p->id,
                'title' => $p->title,
                'views' => (int) ($views[$p->id] ?? 0),
                'carts' => (int) ($carts[$p->id] ?? 0),
                'orders' => (int) ($orders[$p->id] ?? 0),
                'conversion' => ($views[$p->id] ?? 0) > 0 ? round(100 * ($orders[$p->id] ?? 0) / $views[$p->id], 1) : null,
            ])
            ->sortByDesc(fn ($row) => [$row['views'], $row['orders']])->take(10)->values()->all();
    }

    /** @return list<int> 24 numbers: paid orders in each hour of the day (Freetown time) */
    private function hours(Seller $seller, CarbonImmutable $from): array
    {
        $hours = array_fill(0, 24, 0);

        foreach ($this->sold($seller)->where('paid_at', '>=', $from)->pluck('paid_at') as $paidAt) {
            $hours[(int) CarbonImmutable::parse($paidAt)->format('G')]++;
        }

        return $hours;
    }

    /** @return list<array{id: int, slug: string, title: string, stock: int, per_day: float, days_left: int}> products running out within 2 weeks */
    private function forecast(Seller $seller, CarbonImmutable $now): array
    {
        $sold = OrderItem::whereIn('order_id', $this->sold($seller)->where('paid_at', '>=', $now->subDays(14))->select('id'))
            ->selectRaw('product_id, sum(quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id');

        return $seller->products()->where('status', ProductStatus::Active)->whereIn('id', $sold->keys())->get(['id', 'slug', 'title', 'stock'])
            ->map(function ($p) use ($sold) {
                $perDay = round($sold[$p->id] / 14, 2);

                return ['id' => $p->id, 'slug' => $p->slug, 'title' => $p->title, 'stock' => (int) $p->stock, 'per_day' => $perDay, 'days_left' => (int) floor($p->stock / max($perDay, 0.01))];
            })
            ->filter(fn ($row) => $row['days_left'] <= 14)->sortBy('days_left')->values()->all();
    }

    /** Orders that are sales: paid and not refunded, cancelled or rejected. */
    private function sold(Seller $seller): Builder
    {
        return Order::where('seller_id', $seller->id)->whereNotNull('paid_at')
            ->whereIn('status', [...OrderStatus::funded(), OrderStatus::Completed]);
    }

    /**
     * Sales week by week (Monday to Sunday), oldest first, the current week last. Grouped in PHP so it works
     * the same on every database.
     *
     * @return list<array{week_start: string, label: string, sales: string, orders: int}>
     */
    private function weeks(Seller $seller, CarbonImmutable $now): array
    {
        $first = $now->startOfWeek()->subWeeks(self::WEEKS - 1);
        $weeks = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $first->addWeeks($i);
            $weeks[$start->toDateString()] = ['week_start' => $start->toDateString(), 'label' => $start->format('j M'), 'sales' => '0.00', 'orders' => 0];
        }

        foreach ($this->sold($seller)->where('paid_at', '>=', $first)->get(['paid_at', 'subtotal']) as $order) {
            $key = CarbonImmutable::parse($order->paid_at)->startOfWeek()->toDateString();

            if (isset($weeks[$key])) {
                $weeks[$key]['sales'] = Money::add($weeks[$key]['sales'], $order->subtotal);
                $weeks[$key]['orders']++;
            }
        }

        return array_values($weeks);
    }

    /** @return list<array{product_id: int, title: string, quantity: int, sales: string}> */
    private function bestSellers(Seller $seller, CarbonImmutable $from): array
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->sold($seller)->where('paid_at', '>=', $from)->select('id'))
            ->selectRaw('product_id, max(title) as title, sum(quantity) as quantity, sum(line_total) as sales')
            ->groupBy('product_id')
            ->orderByDesc('quantity')->orderByDesc('sales')
            ->limit(5)->get()
            ->map(fn ($row) => ['product_id' => (int) $row->product_id, 'title' => $row->title, 'quantity' => (int) $row->quantity, 'sales' => Money::from($row->sales)])
            ->all();
    }
}
