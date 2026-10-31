<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerReview;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\SettingsService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Home, search and filters, product and shop pages, reviews and the wishlist. */
class StorefrontTest extends TestCase
{
    use Marketplace;

    public function test_the_home_page_shows_categories_deals_new_arrivals_and_top_sellers(): void
    {
        Category::factory()->create(['name' => 'Food & Groceries']);
        $seller = $this->makeSeller([], ['shop_name' => 'Kissy Wholesale', 'rating_avg' => 4.8, 'rating_count' => 12]);
        $this->makeProduct($seller, ['title' => 'Rice deal', 'is_deal' => true]);
        $this->makeProduct($seller, ['title' => 'Fresh arrival']);

        $this->get(route('home'))->assertOk()
            ->assertSee('Buy and sell easy for Salone.')
            ->assertSee('Food &amp; Groceries', false)
            ->assertSee('Deals of the day')->assertSee('Rice deal')
            ->assertSee('New arrivals')->assertSee('Fresh arrival')
            ->assertSee('Top sellers')->assertSee('Kissy Wholesale')
            ->assertSee('placeholder="Search', false);
    }

    public function test_the_home_page_has_an_empty_state(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('No products yet');
    }

    public function test_search_by_keyword_category_price_location_and_verified(): void
    {
        $food = Category::factory()->create(['name' => 'Food']);
        $rice = Category::factory()->create(['name' => 'Rice', 'parent_id' => $food->id]);
        $freetown = $this->makeSeller([], ['district' => 'Western Area Urban', 'is_verified' => true]);
        $bo = $this->makeSeller([], ['district' => 'Bo']);

        $this->makeProduct($freetown, ['title' => 'Parboiled rice 50kg', 'price' => '650.00', 'category_id' => $rice->id]);
        $this->makeProduct($bo, ['title' => 'Country rice 50kg', 'price' => '600.00', 'category_id' => $rice->id]);
        $this->makeProduct($bo, ['title' => 'Solar lamp', 'price' => '150.00']);

        $this->get(route('search', ['q' => 'rice']))->assertOk()
            ->assertSee('Parboiled rice')->assertSee('Country rice')->assertDontSee('Solar lamp');
        $this->get(route('search', ['q' => 'RICE 50kg']))->assertSee('Parboiled rice');
        $this->get(route('categories.show', $food))->assertSee('Parboiled rice')->assertSee('Country rice')->assertDontSee('Solar lamp'); // includes subcategories
        $this->get(route('search', ['min_price' => 620]))->assertSee('Parboiled rice')->assertDontSee('Country rice');
        $this->get(route('search', ['max_price' => 200]))->assertSee('Solar lamp')->assertDontSee('Parboiled rice');
        $this->get(route('search', ['district' => 'Bo']))->assertSee('Country rice')->assertDontSee('Parboiled rice');
        $this->get(route('search', ['verified' => 1]))->assertSee('Parboiled rice')->assertDontSee('Country rice');
        $this->get(route('search', ['q' => 'nothing-matches']))->assertSee('Nothing found');
    }

    public function test_search_sorting(): void
    {
        $this->makeProduct(null, ['title' => 'Cheap thing', 'price' => '10.00', 'rating_avg' => 3]);
        $this->makeProduct(null, ['title' => 'Dear thing', 'price' => '900.00', 'rating_avg' => 5]);

        $this->get(route('search', ['sort' => 'price_asc']))->assertSeeInOrder(['Cheap thing', 'Dear thing']);
        $this->get(route('search', ['sort' => 'price_desc']))->assertSeeInOrder(['Dear thing', 'Cheap thing']);
        $this->get(route('search', ['sort' => 'rating']))->assertSeeInOrder(['Dear thing', 'Cheap thing']);
        $this->get(route('search', ['sort' => 'newest']))->assertSeeInOrder(['Dear thing', 'Cheap thing']);
        $this->get(route('search', ['sort' => 'hacker']))->assertSessionHasErrors('sort');
    }

    public function test_search_treats_wildcards_as_text(): void
    {
        $this->makeProduct(null, ['title' => '100% cotton shirt']);
        $this->makeProduct(null, ['title' => 'Plain shirt']);

        $this->get(route('search', ['q' => '100%']))->assertSee('100% cotton shirt')->assertDontSee('Plain shirt');
    }

    public function test_the_product_page_has_everything_and_open_graph_tags(): void
    {
        $seller = $this->makeSeller([], ['shop_name' => 'Lumley Phones', 'is_verified' => true, 'district' => 'Western Area Urban']);
        $product = Product::factory()->withTiers([10 => '90.00'])->create([
            'seller_id' => $seller->id, 'title' => 'Tecno Spark 20', 'price' => '100.00', 'compare_at_price' => '120.00', 'stock' => 7,
        ]);

        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('Tecno Spark 20')->assertSee('Le 100.00')->assertSee('-17%')
            ->assertSee('Wholesale prices')->assertSee('Le 90.00')
            ->assertSee('7 in stock')
            ->assertSee('Lumley Phones')->assertSee('Verified')
            ->assertSee('Add to cart')->assertSee('Buy now')
            ->assertSee('Share on WhatsApp')->assertSee('https://wa.me/?text=', false)
            ->assertSee(rawurlencode('Buy and sell easy for Salone.'), false)
            ->assertSee('Ask the seller')
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:description"', false)
            ->assertSee('property="og:url"', false)
            ->assertSee('product:price:amount', false);
    }

    public function test_ask_the_seller_is_a_placeholder_until_direct_chat_is_on(): void
    {
        $product = $this->makeProduct();
        $buyer = $this->buyer();

        $this->get(route('products.ask', $product))->assertRedirect(route('login'));
        $this->actingAs($buyer)->get(route('products.ask', $product))->assertOk()->assertSee('Chat with sellers is coming soon');

        config(['chat.direct_enabled' => true]);
        $this->actingAs($buyer)->get(route('products.ask', $product))->assertRedirect(route('chat.direct', $product->seller->user));
    }

    public function test_the_shop_page_lists_products_and_ratings(): void
    {
        $seller = $this->makeSeller([], ['shop_name' => 'Bo Market Traders', 'district' => 'Bo']);
        $this->makeProduct($seller, ['title' => 'Palm oil']);
        $this->makeProduct(null, ['title' => 'Someone else']);

        $this->get(route('shops.show', $seller))->assertOk()
            ->assertSee('Bo Market Traders')->assertSee('Palm oil')->assertDontSee('Someone else')
            ->assertSee('What buyers say');
    }

    // --------------------------------------------------------------------- reviews

    public function test_buyers_review_the_product_and_seller_once_per_completed_order(): void
    {
        $order = $this->shippedOrder();
        $item = $order->items()->first();

        // Not before completion.
        $this->actingAs($order->buyer)->post(route('orders.review', $order), ['seller_rating' => 5])->assertSessionHasErrors('general');

        $this->orders()->complete($order);
        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertSee('Leave a review');

        $this->actingAs($order->buyer)->post(route('orders.review', $order), [
            'seller_rating' => 4, 'seller_comment' => 'Quick and friendly',
            'items' => [$item->id => ['rating' => 5, 'comment' => 'Great rice']],
        ])->assertRedirect(route('orders.show', $order));

        $this->assertSame(4, SellerReview::sole()->rating);
        $this->assertSame('Great rice', Review::sole()->comment);
        $this->assertSame('4.00', $order->seller->fresh()->rating_avg);
        $this->assertSame(1, $order->seller->fresh()->rating_count);
        $this->assertSame('5.00', $item->product->fresh()->rating_avg);

        // Only once.
        $this->actingAs($order->buyer)->post(route('orders.review', $order), ['seller_rating' => 1, 'items' => [$item->id => ['rating' => 1]]]);
        $this->assertSame(1, SellerReview::count());
        $this->assertSame(1, Review::count());
        $this->assertSame('4.00', $order->seller->fresh()->rating_avg);
        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertDontSee('Leave a review');

        // Reviews show on the product page.
        $this->get(route('products.show', $item->product))->assertSee('Great rice');
    }

    public function test_averages_across_orders_and_only_buyers_can_review(): void
    {
        $product = $this->makeProduct(null, ['stock' => 10]);
        foreach ([5, 2] as $stars) {
            $order = $this->shippedOrder($product);
            $this->orders()->complete($order);
            $this->actingAs($order->buyer)->post(route('orders.review', $order), ['seller_rating' => $stars, 'items' => [$order->items()->first()->id => ['rating' => $stars]]]);
        }

        $this->assertSame('3.50', $product->fresh()->rating_avg);
        $this->assertSame(2, $product->fresh()->rating_count);

        $order = $this->shippedOrder($product);
        $this->orders()->complete($order);
        $this->actingAs($this->buyer())->post(route('orders.review', $order), ['seller_rating' => 1])->assertForbidden();
        $this->actingAs($order->buyer)->post(route('orders.review', $order), [])->assertSessionHasErrors('seller_rating');
    }

    // -------------------------------------------------------------------- wishlist

    public function test_the_wishlist(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['title' => 'Solar panel 200W']);

        $this->actingAs($buyer)->get(route('wishlist.index'))->assertSee('Nothing saved yet');

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product))->assertRedirect();
        $this->assertSame(1, WishlistItem::count());
        $this->actingAs($buyer)->get(route('wishlist.index'))->assertSee('Solar panel 200W');
        $this->actingAs($buyer)->get(route('products.show', $product))->assertSee('♥ Saved');

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product));
        $this->assertSame(0, WishlistItem::count());

        $this->app['auth']->guard('web')->forgetUser();
        $this->post(route('wishlist.toggle', $product))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ page smoke

    public function test_every_main_page_renders(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->shippedOrder();
        $seller = $order->seller->user;
        $buyer = $order->buyer;

        foreach ([route('home'), route('search'), route('login')] as $url) {
            $this->get($url)->assertOk();
        }

        foreach ([route('cart.index'), route('orders.index'), route('orders.show', $order), route('wishlist.index'), route('sell.apply'), route('chat.index')] as $url) {
            $this->actingAs($buyer)->get($url)->assertOk();
        }

        foreach ([route('seller.dashboard'), route('seller.products.index'), route('seller.products.create'), route('seller.orders.index'),
            route('seller.orders.show', $order), route('seller.earnings'), route('seller.shop'), route('seller.reviews')] as $url) {
            $this->actingAs($seller)->get($url)->assertOk();
        }

        foreach ([route('admin.dashboard'), route('admin.users.index'), route('admin.sellers.index'), route('admin.sellers.show', $order->seller),
            route('admin.categories.index'), route('admin.categories.create'), route('admin.products.index'), route('admin.orders.index'),
            route('admin.orders.show', $order), route('admin.disputes.index'), route('admin.payouts.index'), route('admin.settings'),
            route('admin.support.index'), route('admin.chat.reports')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($buyer)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_admin_dashboard_numbers(): void
    {
        $order = $this->shippedOrder($this->makeProduct(null, ['price' => '400.00']));
        $this->orders()->complete($order);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Sales today')->assertSee('Le 400.00')
            ->assertSee('Commission this month')->assertSee('Le 20.00');
    }

    public function test_admin_changes_the_commission_and_minimum_payout(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['commission_percent' => '8', 'min_payout' => '250'])->assertRedirect();
        $this->assertSame('8', SettingsService::commissionPercent());
        $this->assertSame('250', SettingsService::minPayout());

        $this->actingAs($admin)->put(route('admin.settings.update'), ['commission_percent' => '80', 'min_payout' => '250'])->assertSessionHasErrors('commission_percent');
        $this->actingAs($this->buyer())->put(route('admin.settings.update'), ['commission_percent' => '1', 'min_payout' => '1'])->assertForbidden();

        $order = $this->paidOrder($this->makeProduct(null, ['price' => '100.00']));
        $this->assertSame('8.00', $order->commission);
    }

    public function test_admin_manages_categories(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'Solar & Power', 'emoji' => '☀️'])->assertRedirect();
        $parent = Category::firstWhere('name', 'Solar & Power');
        $this->assertSame('solar-power', $parent->slug);

        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'Solar Panels', 'parent_id' => $parent->id]);
        $child = Category::firstWhere('name', 'Solar Panels');
        $this->assertSame($parent->id, $child->parent_id);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $parent))->assertSessionHasErrors('delete');
        $this->actingAs($admin)->delete(route('admin.categories.destroy', $child))->assertRedirect();
        $this->assertModelMissing($child);
    }
}
