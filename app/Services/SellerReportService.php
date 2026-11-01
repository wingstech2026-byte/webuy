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
            'low_stock' => $seller->products()->where('status', ProductStatus::Active)->where('stock', '<=', self::LOW_STOCK)
                ->orderBy('stock')->orderBy('title')->limit(10)->get(['id', 'slug', 'title', 'stock', 'unit', 'status'])
                ->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'title' => $p->title, 'stock' => (int) $p->stock, 'unit' => $p->unit, 'status' => $p->status->value])
                ->all(),
        ];
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
