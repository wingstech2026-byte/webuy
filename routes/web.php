<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AgentInspectionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\BusinessAccountController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\FakePaymentController;
use App\Http\Controllers\FarmPageController;
use App\Http\Controllers\GroupPageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PriceCutPageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\Seller;
use App\Http\Controllers\SellerApplicationController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\TrialReviewController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WishlistController;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---- Public catalogue ----------------------------------------------------------
Route::get('/', HomeController::class)->name('home');
Route::get('/search', BrowseController::class)->name('search');
Route::get('/c/{category:slug}', BrowseController::class)->name('categories.show');
Route::get('/p/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/shop/{seller:slug}', [ShopController::class, 'show'])->name('shops.show');

// Development: one phone running the app, to keep open beside the code editor.
Route::get('/phone', function (Request $request) {
    $screens = [
        'Home' => '/', 'Search' => '/search', 'Product' => '/p/local-parboiled-rice-50kg-bag',
        'Cart' => '/cart', 'Orders' => '/orders', 'Seller' => '/seller', 'Admin' => '/admin', 'Log in' => '/login',
    ];
    $open = (string) $request->query('open', '/');

    // Only paths inside this app, never another website.
    if (! str_starts_with($open, '/') || str_starts_with($open, '//')) {
        $open = '/';
    }

    return view('phone', ['screens' => $screens, 'open' => $open]);
})->name('phone');

// Demo: the app shown inside three phone frames (each phone runs the real app).
Route::get('/showcase', function () {
    $product = Product::listed()->where('is_deal', true)->first() ?? Product::listed()->first();
    $seller = App\Models\Seller::approved()->orderByDesc('rating_avg')->first();

    return view('showcase', ['screens' => array_values(array_filter([
        ['1. Shop', 'Search, categories and deals', route('home')],
        $product ? ['2. Product', 'Wholesale prices and WhatsApp share', route('products.show', $product)] : null,
        $seller ? ['3. Seller shop', 'Verified sellers and ratings', route('shops.show', $seller)] : null,
    ]))]);
})->name('showcase');

// ---- Shared links (WhatsApp): referrals, price cuts, WiBuy Farm, "Get the app" ----------------
Route::get('/s/{share:token}', [ShareLinkController::class, 'open'])->name('share.open');
Route::get('/g/{group:token}', [GroupPageController::class, 'show'])->name('groups.show');
Route::get('/get-app', [ShareLinkController::class, 'getApp'])->middleware('throttle:30,1')->name('app.get');
Route::get('/cut/{priceCut:token}', [PriceCutPageController::class, 'show'])->name('price-cuts.show');
Route::post('/cut/{priceCut:token}', [PriceCutPageController::class, 'cut'])->middleware(['auth', 'throttle:10,1'])->name('price-cuts.cut');
Route::get('/farm/{code}', [FarmPageController::class, 'show'])->name('farm.friend');
Route::post('/farm/{code}/water', [FarmPageController::class, 'water'])->middleware(['auth', 'throttle:20,1'])->name('farm.water');

// Payment gateway callback (CSRF-exempt, see bootstrap/app.php)
Route::post('/webhooks/pawapay', [WebhookController::class, 'pawapay'])
    ->middleware('throttle:webhook')->name('webhooks.pawapay');

// ---- Phone + SMS code login ------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'sendCode'])->middleware('throttle:otp-send')->name('login.send');
    Route::get('/login/verify', [LoginController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/verify', [LoginController::class, 'verify'])->middleware('throttle:otp-verify')->name('login.confirm');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---- Buyers ------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/p/{product:slug}/ask', [ProductController::class, 'ask'])->name('products.ask');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart/{product:slug}', [CartController::class, 'store'])->middleware('throttle:cart')->name('cart.add');
    Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('cart.remove');

    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');
    Route::get('/checkout/{checkout}', [CheckoutController::class, 'status'])->name('checkout.status');
    Route::post('/checkout/{checkout}/cancel', [CheckoutController::class, 'cancel'])->name('checkout.cancel');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/review', [OrderController::class, 'review'])->name('orders.review');
    Route::get('/orders/{order}/problem', [DisputeController::class, 'create'])->name('disputes.create');
    Route::post('/orders/{order}/problem', [DisputeController::class, 'store'])->middleware('throttle:10,1')->name('disputes.store');
    Route::get('/orders/{order}/return', [ReturnController::class, 'create'])->name('returns.create');
    Route::post('/orders/{order}/return', [ReturnController::class, 'store'])->middleware('throttle:10,1')->name('returns.store');
    Route::get('/problems/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');

    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/{product:slug}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

    Route::get('/trials/{trialCampaign}/review', [TrialReviewController::class, 'create'])->name('trials.review');
    Route::post('/trials/{trialCampaign}/review', [TrialReviewController::class, 'store'])->middleware('throttle:10,1')->name('trials.review.store');

    Route::get('/business', [BusinessAccountController::class, 'create'])->name('business.create');
    Route::post('/business', [BusinessAccountController::class, 'store'])->middleware('throttle:10,1')->name('business.store');
    Route::get('/agent/inspections', [AgentInspectionController::class, 'index'])->name('agent.inspections.index');
    Route::get('/agent/inspections/{inspection}', [AgentInspectionController::class, 'edit'])->name('agent.inspections.edit');
    Route::post('/agent/inspections/{inspection}', [AgentInspectionController::class, 'update'])->middleware('throttle:20,1')->name('agent.inspections.update');

    Route::get('/sell', [SellerApplicationController::class, 'create'])->name('sell.apply');
    Route::post('/sell', [SellerApplicationController::class, 'store'])->middleware('throttle:10,1')->name('sell.store');

    // Chat: the WiBuy team (support) and, behind CHAT_DIRECT_ENABLED, buyer <-> seller chats
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('/unread', [ChatController::class, 'unread'])->name('unread');
        Route::post('/ping', [ChatController::class, 'ping'])->name('ping');
        Route::get('/direct/{user}', [ChatController::class, 'direct'])->whereNumber('user')->name('direct');
        Route::post('/messages/{message}/report', [ChatController::class, 'report'])
            ->whereNumber('message')->middleware('throttle:10,1')->name('report');

        Route::get('/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation')->name('show');
        Route::get('/{conversation}/messages', [ChatController::class, 'messages'])->whereNumber('conversation')->name('messages');
        Route::post('/{conversation}/messages', [ChatController::class, 'store'])
            ->whereNumber('conversation')->middleware('throttle:chat-send')->name('store');
        Route::post('/{conversation}/read', [ChatController::class, 'read'])->whereNumber('conversation')->name('read');
        Route::post('/{conversation}/mute', [ChatController::class, 'mute'])->whereNumber('conversation')->name('mute');
    });

    // Fake gateway buttons (404 unless PAYMENT_GATEWAY=fake outside production)
    Route::post('/fake-pay/{payment}/approve', [FakePaymentController::class, 'approve'])->name('fake.approve');
    Route::post('/fake-pay/{payment}/decline', [FakePaymentController::class, 'decline'])->name('fake.decline');
});

// ---- Sellers (approved shops only) ---------------------------------------------------
Route::middleware(['auth', 'seller'])->prefix('seller')->name('seller.')->group(function () {
    Route::get('/', Seller\DashboardController::class)->name('dashboard');

    Route::resource('products', Seller\ProductController::class)->except('show');

    Route::get('/orders', [Seller\OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [Seller\OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/accept', [Seller\OrderController::class, 'accept'])->name('orders.accept');
    Route::post('/orders/{order}/reject', [Seller\OrderController::class, 'reject'])->name('orders.reject');
    Route::post('/orders/{order}/ship', [Seller\OrderController::class, 'ship'])->name('orders.ship');
    Route::post('/orders/{order}/deliver', [Seller\OrderController::class, 'deliver'])->name('orders.deliver');

    Route::get('/earnings', [Seller\EarningsController::class, 'index'])->name('earnings');
    Route::post('/payouts', [Seller\EarningsController::class, 'requestPayout'])->middleware('throttle:10,1')->name('payouts.store');

    Route::get('/shop', [Seller\ShopController::class, 'edit'])->name('shop');
    Route::put('/shop', [Seller\ShopController::class, 'update'])->name('shop.update');
    Route::put('/shop/delivery', [Seller\ShopController::class, 'updateDelivery'])->name('shop.delivery');

    Route::get('/reviews', Seller\ReviewsController::class)->name('reviews');

    // Requests for quotation from buyers
    Route::get('/rfqs', [Seller\RfqController::class, 'index'])->name('rfqs.index');
    Route::post('/rfqs/{rfq}/quote', [Seller\RfqController::class, 'quote'])->middleware('throttle:30,1')->name('rfqs.quote');

    // Promotions: "cut the price" products and free trials
    Route::get('/promotions', [Seller\PromotionController::class, 'index'])->name('promotions');
    Route::post('/promotions/price-cuts', [Seller\PromotionController::class, 'storeCut'])->name('promotions.cuts.store');
    Route::post('/promotions/price-cuts/{priceCutCampaign}/toggle', [Seller\PromotionController::class, 'toggleCut'])->name('promotions.cuts.toggle');
    Route::post('/promotions/trials', [Seller\PromotionController::class, 'storeTrial'])->name('promotions.trials.store');
    Route::post('/promotions/trial-applications/{trialApplication}/select', [Seller\PromotionController::class, 'select'])->name('promotions.trials.select');
    Route::post('/promotions/trial-applications/{trialApplication}/reject', [Seller\PromotionController::class, 'reject'])->name('promotions.trials.reject');
    Route::post('/problems/{dispute}/respond', [Seller\DisputeController::class, 'respond'])->name('disputes.respond');

    // Returns from buyers
    Route::post('/returns/{returnRequest}/accept', [Seller\ReturnController::class, 'accept'])->name('returns.accept');
    Route::post('/returns/{returnRequest}/refuse', [Seller\ReturnController::class, 'refuse'])->name('returns.refuse');
    Route::post('/returns/{returnRequest}/received', [Seller\ReturnController::class, 'received'])->name('returns.received');

    // Shop locations on the map, delivery radius and pickup points
    Route::get('/locations', [Seller\LocationController::class, 'index'])->name('locations');
    Route::post('/locations', [Seller\LocationController::class, 'save'])->name('locations.store');
    Route::post('/locations/{location}', [Seller\LocationController::class, 'save'])->name('locations.update');

    // Group buying offers
    Route::get('/group-buys', [Seller\GroupBuyController::class, 'index'])->name('group-buys.index');
    Route::post('/group-buys', [Seller\GroupBuyController::class, 'store'])->name('group-buys.store');
    Route::post('/group-buys/{groupBuyCampaign}/toggle', [Seller\GroupBuyController::class, 'toggle'])->name('group-buys.toggle');
});

// ---- Admin ------------------------------------------------------------------------------
Route::middleware(['auth', 'admin', 'audit'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');
    Route::get('/audit', [Admin\AuditLogController::class, 'index'])->name('audit');
    Route::get('/staff', [Admin\StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [Admin\StaffController::class, 'store'])->name('staff.store');
    Route::post('/staff/{user}/remove', [Admin\StaffController::class, 'remove'])->name('staff.remove');
    Route::get('/group-buys', [Admin\GroupBuyController::class, 'index'])->name('group-buys.index');
    Route::post('/group-buys/{groupBuyCampaign}/toggle', [Admin\GroupBuyController::class, 'toggle'])->name('group-buys.toggle');

    Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');

    Route::get('/sellers', [Admin\SellerController::class, 'index'])->name('sellers.index');
    Route::get('/sellers/{seller:slug}', [Admin\SellerController::class, 'show'])->name('sellers.show');
    Route::post('/sellers/{seller:slug}/approve', [Admin\SellerController::class, 'approve'])->name('sellers.approve');
    Route::post('/sellers/{seller:slug}/reject', [Admin\SellerController::class, 'reject'])->name('sellers.reject');
    Route::post('/sellers/{seller:slug}/suspend', [Admin\SellerController::class, 'suspend'])->name('sellers.suspend');
    Route::post('/sellers/{seller:slug}/verify', [Admin\SellerController::class, 'verify'])->name('sellers.verify');
    Route::post('/sellers/{seller:slug}/commission', [Admin\SellerController::class, 'commission'])->name('sellers.commission');

    Route::resource('categories', Admin\CategoryController::class)->except('show');

    Route::get('/products', [Admin\ProductController::class, 'index'])->name('products.index');
    Route::post('/products/{product:slug}/suspend', [Admin\ProductController::class, 'suspend'])->name('products.suspend');
    Route::post('/products/{product:slug}/feature', [Admin\ProductController::class, 'feature'])->name('products.feature');

    Route::get('/orders', [Admin\OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [Admin\OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/retry-refund', [Admin\OrderController::class, 'retryRefund'])->name('orders.retry-refund');

    Route::get('/disputes', [Admin\DisputeController::class, 'index'])->name('disputes.index');
    Route::post('/disputes/{dispute}/resolve', [Admin\DisputeController::class, 'resolve'])->name('disputes.resolve');

    Route::get('/payouts', [Admin\PayoutController::class, 'index'])->name('payouts.index');
    Route::post('/payouts/{payout}/approve', [Admin\PayoutController::class, 'approve'])->name('payouts.approve');
    Route::post('/payouts/{payout}/reject', [Admin\PayoutController::class, 'reject'])->name('payouts.reject');

    // Engagement: rewards, spin wheel, lightning deals, price cuts, WiBuy Farm, free trials, WiBuy Pass
    Route::get('/rewards', [Admin\EngagementController::class, 'index'])->name('rewards.index');
    Route::get('/rewards/settings', [Admin\RewardSettingsController::class, 'edit'])->name('rewards.settings');
    Route::put('/rewards/settings', [Admin\RewardSettingsController::class, 'update'])->name('rewards.settings.update');
    Route::get('/rewards/spin', [Admin\SpinPrizeController::class, 'index'])->name('rewards.spin');
    Route::post('/rewards/spin', [Admin\SpinPrizeController::class, 'store'])->name('rewards.spin.store');
    Route::put('/rewards/spin/{spinPrize}', [Admin\SpinPrizeController::class, 'update'])->name('rewards.spin.update');
    Route::delete('/rewards/spin/{spinPrize}', [Admin\SpinPrizeController::class, 'destroy'])->name('rewards.spin.destroy');
    Route::get('/rewards/deals', [Admin\LightningDealController::class, 'index'])->name('rewards.deals');
    Route::post('/rewards/deals', [Admin\LightningDealController::class, 'store'])->name('rewards.deals.store');
    Route::post('/rewards/deals/rotate', [Admin\LightningDealController::class, 'rotate'])->name('rewards.deals.rotate');
    Route::delete('/rewards/deals/{flashDeal}', [Admin\LightningDealController::class, 'destroy'])->name('rewards.deals.destroy');
    Route::get('/rewards/price-cuts', [Admin\PriceCutCampaignController::class, 'index'])->name('rewards.cuts');
    Route::post('/rewards/price-cuts', [Admin\PriceCutCampaignController::class, 'store'])->name('rewards.cuts.store');
    Route::post('/rewards/price-cuts/{priceCutCampaign}/toggle', [Admin\PriceCutCampaignController::class, 'toggle'])->name('rewards.cuts.toggle');
    Route::get('/rewards/farm', [Admin\FarmController::class, 'index'])->name('rewards.farm');
    Route::put('/rewards/farm/missions/{farmMission}', [Admin\FarmController::class, 'updateMission'])->name('rewards.farm.mission');
    Route::post('/rewards/farm/rewards', [Admin\FarmController::class, 'storeReward'])->name('rewards.farm.reward');
    Route::post('/rewards/farm/claims/{rewardClaim}/fulfil', [Admin\FarmController::class, 'fulfil'])->name('rewards.farm.fulfil');
    Route::get('/rewards/trials', [Admin\TrialController::class, 'index'])->name('rewards.trials');
    Route::post('/rewards/trials/{trialCampaign}/close', [Admin\TrialController::class, 'close'])->name('rewards.trials.close');
    Route::post('/rewards/trial-applications/{trialApplication}/select', [Admin\TrialController::class, 'select'])->name('rewards.trials.select');
    Route::post('/rewards/trial-applications/{trialApplication}/reject', [Admin\TrialController::class, 'reject'])->name('rewards.trials.reject');

    // Business buying: verification, inspections and agents, WiBuy Global, AI assistant usage
    Route::get('/business', [Admin\BusinessController::class, 'index'])->name('business.index');
    Route::post('/business/{businessProfile}/verify', [Admin\BusinessController::class, 'verify'])->name('business.verify');
    Route::post('/business/{businessProfile}/reject', [Admin\BusinessController::class, 'reject'])->name('business.reject');
    Route::get('/inspections', [Admin\InspectionController::class, 'index'])->name('inspections.index');
    Route::post('/inspections/{inspection}/assign', [Admin\InspectionController::class, 'assign'])->name('inspections.assign');
    Route::post('/agents', [Admin\InspectionController::class, 'toggleAgent'])->name('agents.toggle');
    Route::get('/global', [Admin\ImportController::class, 'index'])->name('global.index');
    Route::post('/global/products', [Admin\ImportController::class, 'store'])->name('global.products.store');
    Route::post('/global/products/{importProduct}/toggle', [Admin\ImportController::class, 'toggle'])->name('global.products.toggle');
    Route::post('/global/orders/{importOrder}/advance', [Admin\ImportController::class, 'advance'])->name('global.orders.advance');
    Route::get('/assistant', [Admin\AssistantLogController::class, 'index'])->name('assistant.index');

    Route::get('/settings', [Admin\SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');

    // Support inbox and chat moderation
    Route::get('/support', [Admin\SupportController::class, 'index'])->name('support.index');
    Route::get('/support/{conversation}', [Admin\SupportController::class, 'show'])->whereNumber('conversation')->name('support.show');
    Route::get('/chat/reports', [Admin\ChatModerationController::class, 'reports'])->name('chat.reports');
    Route::post('/chat/messages/{message}/delete', [Admin\ChatModerationController::class, 'destroy'])->name('chat.messages.delete');
    Route::post('/chat/messages/{message}/dismiss', [Admin\ChatModerationController::class, 'dismiss'])->name('chat.messages.dismiss');
    Route::post('/chat/users/{user}/ban', [Admin\ChatModerationController::class, 'toggleBan'])->name('chat.users.ban');
});
