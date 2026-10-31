<?php

namespace Database\Seeders;

use App\Enums\CheckoutStatus;
use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Models\Category;
use App\Models\Checkout;
use App\Models\Image;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerDeliveryArea;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReviewService;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample data for development and demos: 1 admin, 5 approved sellers in Freetown, Bo and Kenema,
 * categories, 40 products (some with wholesale tiers), a few completed orders and reviews.
 * Prices are examples in SLE. Run on an empty database: php artisan migrate:fresh --seed
 */
class DatabaseSeeder extends Seeder
{
    private const CATEGORIES = [
        ['Food & Groceries', '🍚', ['Rice & Grains', 'Cooking Oil', 'Drinks', 'Spices & Seasoning']],
        ['Phones & Accessories', '📱', ['Smartphones', 'Basic Phones', 'Chargers & Cables', 'Power Banks']],
        ['Fashion', '👗', ['Women', 'Men', 'Shoes', 'African Print']],
        ['Beauty & Hair', '💄', ['Hair & Wigs', 'Skin Care', 'Perfume']],
        ['Home & Kitchen', '🍳', ['Cookware', 'Furniture', 'Cleaning']],
        ['Electronics', '📺', ['TV & Audio', 'Fans & Coolers', 'Computers']],
        ['Building Materials', '🧱', ['Cement', 'Roofing', 'Paint', 'Plumbing']],
        ['Solar & Power', '☀️', ['Solar Panels', 'Batteries & Inverters', 'Lamps & Torches', 'Generators']],
        ['Baby & Kids', '🧸', ['Diapers', 'Baby Food', 'Toys']],
        ['Wholesale Bulk Deals', '📦', ['Bulk Food', 'Bulk Household']],
    ];

    /** key => [shop name, district, area, pickup address, verified, delivery fees] */
    private const SELLERS = [
        'kissy' => ['Kissy Road Wholesale', 'Western Area Urban', 'Kissy', '45 Kissy Road, opposite the market gate', true,
            ['Western Area Urban' => '25.00', 'Western Area Rural' => '40.00', 'Port Loko' => '90.00']],
        'lumley' => ['Lumley Phone Centre', 'Western Area Urban', 'Lumley', 'Lumley Roundabout, shop 12', true,
            ['Western Area Urban' => '20.00', 'Western Area Rural' => '35.00']],
        'fashion' => ['Salone Fashion House', 'Western Area Urban', 'Wilberforce', '8 Wilkinson Road, Wilberforce', false,
            ['Western Area Urban' => '20.00', 'Bo' => '60.00', 'Kenema' => '70.00']],
        'bo' => ['Bo Market Traders', 'Bo', 'Bo town', 'Bo main market, stall 21', true,
            ['Bo' => '15.00', 'Kenema' => '45.00', 'Moyamba' => '50.00']],
        'kenema' => ['Kenema Solar & Power', 'Kenema', 'Kenema town', '17 Hangha Road, Kenema', false,
            ['Kenema' => '20.00', 'Bo' => '50.00', 'Kailahun' => '60.00', 'Western Area Urban' => '120.00']],
    ];

    /** [seller, category, title, price, compare_at, unit, stock, min order, tiers (min => price), deal, featured] */
    private const PRODUCTS = [
        ['kissy', 'Rice & Grains', 'Local parboiled rice, 50kg bag', '650.00', '720.00', '50kg bag', 120, 1, [10 => '620.00', 50 => '590.00'], true, true],
        ['kissy', 'Rice & Grains', 'Imported long grain rice, 25kg', '360.00', null, '25kg bag', 200, 1, [20 => '340.00'], false, false],
        ['kissy', 'Cooking Oil', 'Palm oil, 20 litre gallon', '480.00', '520.00', '20L gallon', 60, 1, [10 => '455.00'], true, false],
        ['kissy', 'Cooking Oil', 'Vegetable oil, 5 litre', '135.00', null, '5L bottle', 150, 1, [12 => '125.00', 48 => '118.00'], false, false],
        ['kissy', 'Spices & Seasoning', 'Seasoning cubes, carton of 24 packs', '210.00', null, 'carton', 80, 1, [10 => '195.00'], false, false],
        ['kissy', 'Bulk Food', 'Sugar, 50kg bag', '780.00', '850.00', '50kg bag', 40, 1, [5 => '750.00', 20 => '720.00'], true, false],
        ['kissy', 'Bulk Food', 'Onions, 25kg bag', '290.00', null, '25kg bag', 70, 2, [10 => '270.00'], false, false],
        ['kissy', 'Bulk Household', 'Laundry soap, carton of 48 bars', '410.00', null, 'carton', 45, 1, [10 => '385.00'], false, true],
        ['kissy', 'Drinks', 'Bottled water, pack of 12', '45.00', null, 'pack', 300, 1, [20 => '40.00', 100 => '36.00'], false, false],
        ['kissy', 'Bulk Household', 'Toilet roll, bale of 48', '260.00', null, 'bale', 50, 1, [], false, false],
        ['lumley', 'Smartphones', 'Tecno Spark 20, 128GB', '2450.00', '2700.00', 'phone', 15, 1, [], true, true],
        ['lumley', 'Smartphones', 'Samsung Galaxy A15, 128GB', '3100.00', null, 'phone', 10, 1, [], false, true],
        ['lumley', 'Smartphones', 'Itel A70, 64GB', '1350.00', '1500.00', 'phone', 25, 1, [5 => '1290.00'], false, false],
        ['lumley', 'Basic Phones', 'Nokia 105 dual SIM', '320.00', null, 'phone', 40, 1, [10 => '295.00'], false, false],
        ['lumley', 'Chargers & Cables', 'Fast charger with USB-C cable', '85.00', '110.00', 'piece', 100, 1, [20 => '70.00'], true, false],
        ['lumley', 'Power Banks', 'Power bank 20,000mAh', '390.00', null, 'piece', 35, 1, [10 => '360.00'], false, false],
        ['lumley', 'Chargers & Cables', 'Earphones with mic', '60.00', null, 'piece', 120, 1, [], false, false],
        ['lumley', 'Computers', 'Used laptop HP EliteBook 840, 8GB', '4800.00', null, 'laptop', 5, 1, [], false, false],
        ['fashion', 'African Print', 'Ankara fabric, 6 yards', '240.00', '280.00', '6 yards', 60, 1, [10 => '220.00'], true, false],
        ['fashion', 'Women', 'Ladies gara dress', '350.00', null, 'dress', 25, 1, [], false, true],
        ['fashion', 'Men', 'Men\'s kaftan, embroidered', '420.00', null, 'piece', 20, 1, [], false, false],
        ['fashion', 'Shoes', 'Men\'s leather sandals', '260.00', null, 'pair', 30, 1, [], false, false],
        ['fashion', 'Women', 'Head tie (gele), assorted colours', '90.00', null, 'piece', 80, 1, [12 => '80.00'], false, false],
        ['fashion', 'Hair & Wigs', 'Human hair wig, 18 inch', '1600.00', '1850.00', 'wig', 8, 1, [], false, false],
        ['fashion', 'Skin Care', 'Shea butter, 500g', '75.00', null, 'jar', 90, 1, [24 => '65.00'], false, false],
        ['fashion', 'Perfume', 'Body spray, 200ml', '55.00', null, 'bottle', 70, 1, [], false, false],
        ['bo', 'Rice & Grains', 'Bo country rice, 50kg', '600.00', null, '50kg bag', 80, 1, [10 => '575.00'], false, false],
        ['bo', 'Cooking Oil', 'Fresh palm oil, 4 litre', '95.00', null, '4L gallon', 100, 1, [10 => '88.00'], false, false],
        ['bo', 'Spices & Seasoning', 'Dried pepper, 1kg', '40.00', null, 'kg', 150, 2, [20 => '35.00'], false, false],
        ['bo', 'Cookware', 'Aluminium cooking pot set, 3 pieces', '330.00', '380.00', 'set', 20, 1, [], true, false],
        ['bo', 'Cement', 'Leocem cement, 50kg', '135.00', null, '50kg bag', 300, 5, [50 => '128.00', 200 => '122.00'], false, true],
        ['bo', 'Roofing', 'Zinc roofing sheet, 0.3mm', '95.00', null, 'sheet', 400, 10, [100 => '88.00'], false, false],
        ['bo', 'Paint', 'Emulsion paint, 20 litre', '520.00', null, 'bucket', 25, 1, [], false, false],
        ['bo', 'Diapers', 'Baby diapers, pack of 40', '145.00', null, 'pack', 60, 1, [10 => '135.00'], false, false],
        ['kenema', 'Solar Panels', 'Solar panel 200W', '1950.00', '2200.00', 'panel', 18, 1, [5 => '1850.00'], true, true],
        ['kenema', 'Batteries & Inverters', 'Deep cycle battery 100Ah', '2600.00', null, 'battery', 10, 1, [], false, false],
        ['kenema', 'Batteries & Inverters', 'Inverter 1.5kVA', '2300.00', null, 'piece', 8, 1, [], false, false],
        ['kenema', 'Lamps & Torches', 'Rechargeable solar lamp', '150.00', '180.00', 'lamp', 75, 1, [10 => '135.00', 50 => '120.00'], true, false],
        ['kenema', 'Generators', 'Petrol generator 2.5kVA, used', '3900.00', null, 'generator', 3, 1, [], false, false],
        ['kenema', 'Fans & Coolers', 'Rechargeable standing fan', '480.00', null, 'fan', 22, 1, [], false, false],
    ];

    private const COLOURS = [[21, 128, 61], [234, 179, 8], [2, 132, 199], [190, 24, 93], [124, 58, 237], [234, 88, 12]];

    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['phone' => PhoneNumber::normalize(config('wibuy.admin.phone'))],
            ['name' => config('wibuy.admin.name'), 'is_admin' => true],
        );

        $categories = $this->categories();
        $sellers = $this->sellers();
        $products = $this->products($sellers, $categories);
        $this->ordersAndReviews($products);
        $this->call(EngagementSeeder::class);
        $this->call(BusinessSeeder::class);
        $this->call(GeoGroupSeeder::class);

        $this->command?->info("Admin login: {$admin->display_phone}. 5 sellers, ".count($products).' products.');
    }

    /** @return array<string, Category> subcategory name => category */
    private function categories(): array
    {
        $byName = [];

        foreach (self::CATEGORIES as $i => [$name, $emoji, $children]) {
            $parent = Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'emoji' => $emoji, 'sort_order' => $i]);
            $byName[$name] = $parent;

            foreach ($children as $j => $child) {
                $byName[$child] = Category::firstOrCreate(
                    ['slug' => Str::slug($child)],
                    ['name' => $child, 'parent_id' => $parent->id, 'sort_order' => $j],
                );
            }
        }

        return $byName;
    }

    /** @return array<string, Seller> */
    private function sellers(): array
    {
        $sellers = [];
        $i = 1;

        foreach (self::SELLERS as $key => [$name, $district, $area, $address, $verified, $fees]) {
            $user = User::firstOrCreate(['phone' => '2327710000'.$i], ['name' => "Owner of {$name}"]);

            $seller = Seller::firstOrCreate(['user_id' => $user->id], [
                'shop_name' => $name,
                'slug' => Slug::unique(Seller::class, $name),
                'description' => "{$name}: good prices and honest service in {$area}.",
                'district' => $district,
                'area' => $area,
                'pickup_address' => $address,
                'payout_phone' => $user->phone,
                'payout_operator' => $i % 2 ? Operator::Orange : Operator::Afrimoney,
                'status' => SellerStatus::Approved,
                'is_verified' => $verified,
                'offers_delivery' => true,
                'offers_pickup' => true,
                'approved_at' => now()->subDays(30),
            ]);

            foreach ($fees as $feeDistrict => $fee) {
                SellerDeliveryArea::firstOrCreate(['seller_id' => $seller->id, 'district' => $feeDistrict], ['fee' => $fee]);
            }

            $sellers[$key] = $seller;
            $i++;
        }

        return $sellers;
    }

    /** @return array<int, Product> */
    private function products(array $sellers, array $categories): array
    {
        $products = [];

        foreach (self::PRODUCTS as $n => [$seller, $category, $title, $price, $compare, $unit, $stock, $min, $tiers, $deal, $featured]) {
            $product = Product::firstOrCreate(['slug' => Str::slug($title)], [
                'seller_id' => $sellers[$seller]->id,
                'category_id' => $categories[$category]->id,
                'title' => $title,
                'description' => "{$title}. Sold by {$sellers[$seller]->shop_name}. Ask for bulk prices if you need more.",
                'price' => $price,
                'compare_at_price' => $compare,
                'stock' => $stock,
                'unit' => $unit,
                'min_order_quantity' => $min,
                'condition' => str_contains(strtolower($title), 'used') ? ProductCondition::Used : ProductCondition::New,
                'status' => ProductStatus::Active,
                'is_deal' => $deal,
                'is_featured' => $featured,
            ]);

            foreach ($tiers as $minQty => $unitPrice) {
                PriceTier::firstOrCreate(['product_id' => $product->id, 'min_quantity' => $minQty], ['unit_price' => $unitPrice]);
            }

            if ($product->images()->doesntExist()) {
                $this->placeholderImage($product, self::COLOURS[$n % count(self::COLOURS)]);
            }

            $products[] = $product;
        }

        return $products;
    }

    /** A small coloured JPEG with the product name, so the demo looks right before real photos exist. */
    private function placeholderImage(Product $product, array $rgb): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $render = function (int $size) use ($product, $rgb) {
            $img = imagecreatetruecolor($size, $size);
            imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
            $white = imagecolorallocate($img, 255, 255, 255);
            $lines = explode("\n", wordwrap($product->title, $size > 400 ? 26 : 18, "\n", true));
            $y = (int) ($size / 2 - count($lines) * 10);

            foreach ($lines as $line) {
                $x = max(4, (int) (($size - strlen($line) * imagefontwidth(5)) / 2));
                imagestring($img, 5, $x, $y, $line, $white);
                $y += 22;
            }

            ob_start();
            imagejpeg($img, null, 70);

            return ob_get_clean();
        };

        $base = 'images/gallery/seed/'.$product->slug;
        Storage::disk(Image::DISK)->put("{$base}.jpg", $render(600));
        Storage::disk(Image::DISK)->put("{$base}-thumb.jpg", $render(240));

        Image::create([
            'imageable_type' => $product->getMorphClass(),
            'imageable_id' => $product->id,
            'collection' => 'gallery',
            'path' => "{$base}.jpg",
            'thumb_path' => "{$base}-thumb.jpg",
            'status' => 'ready',
        ]);
    }

    /** A few completed orders with reviews, so ratings, balances and "sold" counts are not all zero. */
    private function ordersAndReviews(array $products): void
    {
        if (Order::exists()) {
            return;
        }

        $buyers = collect(['Aminata Kamara', 'Mohamed Sesay', 'Fatmata Bangura'])->map(
            fn ($name, $i) => User::firstOrCreate(['phone' => '2327620000'.($i + 1)], ['name' => $name])
        );
        $ledger = app(LedgerService::class);
        $reviews = app(ReviewService::class);
        $comments = ['Good quality, fast delivery.', 'Exactly as described. Thank you!', 'Fair price. Will buy again.', 'Seller was very helpful.', null];

        foreach (array_slice($products, 0, 30, true) as $n => $product) {
            if ($n % 3 !== 0) {
                continue;
            }

            $buyer = $buyers[$n % 3];
            $quantity = $product->min_order_quantity;
            $unit = $product->unitPriceFor($quantity);
            $subtotal = Money::mul($unit, $quantity);
            $commission = Money::percentOf($subtotal, config('wibuy.commission_percent'));
            $when = now()->subDays(20 - $n % 15);

            $checkout = Checkout::create([
                'buyer_id' => $buyer->id, 'total' => $subtotal, 'status' => CheckoutStatus::Paid,
                'payer_phone' => $buyer->phone, 'operator' => Operator::Orange, 'paid_at' => $when,
            ]);

            $order = Order::create([
                'checkout_id' => $checkout->id, 'buyer_id' => $buyer->id, 'seller_id' => $product->seller_id,
                'status' => OrderStatus::Completed, 'subtotal' => $subtotal, 'delivery_fee' => '0.00',
                'commission' => $commission, 'commission_percent' => config('wibuy.commission_percent'), 'total' => $subtotal,
                'delivery_method' => DeliveryMethod::Pickup, 'recipient_name' => $buyer->name, 'recipient_phone' => $buyer->phone,
                'stock_reserved' => true, 'paid_at' => $when, 'accepted_at' => $when, 'shipped_at' => $when,
                'completed_at' => $when->copy()->addDay(), 'created_at' => $when,
            ]);

            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id, 'title' => $product->title, 'unit' => $product->unit,
                'unit_price' => $unit, 'quantity' => $quantity, 'line_total' => $subtotal,
                'thumb_path' => $product->images()->value('thumb_path'),
            ]);

            $ledger->creditSale($order);
            $product->increment('sold_count', $quantity);

            $item = $order->items()->first();
            $reviews->submit($order, $buyer, 4 + $n % 2, $comments[$n % 5], [
                $item->id => ['rating' => 5 - $n % 2, 'comment' => $comments[($n + 1) % 5]],
            ]);
        }
    }
}
