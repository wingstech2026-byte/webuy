<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| REST API for the WiBuy mobile app (Flutter). Base URL: {APP_URL}/api/v1
| Login returns a Sanctum token; send it as "Authorization: Bearer <token>".
| Errors are JSON: 401 not logged in, 403 not yours, 404 not found, 422 with a message people can read.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/meta', [V1\CatalogController::class, 'meta'])->name('meta');
    Route::get('/home', [V1\CatalogController::class, 'home'])->name('home');
    Route::get('/categories', [V1\CatalogController::class, 'categories'])->name('categories');
    Route::get('/products', [V1\CatalogController::class, 'products'])->name('products.index');
    Route::get('/products/{product:slug}', [V1\CatalogController::class, 'product'])->name('products.show');
    Route::get('/shops/{seller:slug}', [V1\CatalogController::class, 'shop'])->name('shops.show');

    // Engagement (Part A): public parts
    Route::get('/lightning-deals', [V1\FlashDealController::class, 'index'])->name('lightning-deals.index');
    Route::get('/price-cuts/{priceCut:token}', [V1\PriceCutController::class, 'show'])->name('price-cuts.show');
    Route::get('/farm/friends/{code}', [V1\FarmController::class, 'friend'])->name('farm.friend');

    // Shops near a point (only public shop locations; a home business shows its area, not its door)
    Route::get('/geo/nearby', [V1\GeoController::class, 'nearby'])->middleware('throttle:60,1')->name('geo.nearby');

    // Group buying: public offers and group progress
    Route::get('/group-buys', [V1\GroupBuyController::class, 'index'])->name('group-buys.index');
    Route::get('/group-buys/{campaign}', [V1\GroupBuyController::class, 'show'])->whereNumber('campaign')->name('group-buys.show');
    Route::get('/groups/{group:token}', [V1\GroupBuyController::class, 'group'])->name('groups.show');
    Route::get('/app/deferred-link', [V1\AppLinkController::class, 'deferred'])->middleware('throttle:30,1')->name('app.deferred-link');

    Route::post('/auth/code', [V1\AuthController::class, 'sendCode'])->middleware('throttle:otp-send')->name('auth.code');
    Route::post('/auth/verify', [V1\AuthController::class, 'verify'])->middleware('throttle:otp-verify')->name('auth.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [V1\AuthController::class, 'me'])->name('me');
        Route::post('/auth/logout', [V1\AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/cart', [V1\CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/{product:slug}', [V1\CartController::class, 'store'])->middleware('throttle:cart')->name('cart.store');
        Route::patch('/cart/items/{item}', [V1\CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/items/{item}', [V1\CartController::class, 'destroy'])->name('cart.destroy');

        Route::post('/checkout/quote', [V1\CheckoutController::class, 'quote'])->name('checkout.quote');
        Route::post('/checkout', [V1\CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');
        Route::get('/checkouts/{checkout}', [V1\CheckoutController::class, 'show'])->name('checkouts.show');
        Route::post('/checkouts/{checkout}/cancel', [V1\CheckoutController::class, 'cancel'])->name('checkouts.cancel');
        Route::post('/checkouts/{checkout}/simulate', [V1\CheckoutController::class, 'simulate'])->name('checkouts.simulate');

        Route::get('/orders', [V1\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [V1\OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/confirm', [V1\OrderController::class, 'confirm'])->name('orders.confirm');
        Route::post('/orders/{order}/review', [V1\OrderController::class, 'review'])->name('orders.review');

        Route::get('/wishlist', [V1\WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('/wishlist/{product:slug}', [V1\WishlistController::class, 'toggle'])->name('wishlist.toggle');

        // Rewards hub, points, coupons and sharing
        Route::get('/rewards', [V1\RewardsController::class, 'hub'])->name('rewards.hub');
        Route::post('/rewards/check-in', [V1\RewardsController::class, 'checkIn'])->name('rewards.check-in');
        Route::get('/coupons', [V1\RewardsController::class, 'coupons'])->name('coupons.index');
        Route::get('/points', [V1\RewardsController::class, 'points'])->name('points.index');
        Route::post('/points/exchange', [V1\RewardsController::class, 'exchange'])->middleware('throttle:10,1')->name('points.exchange');
        Route::post('/shares', [V1\RewardsController::class, 'share'])->middleware('throttle:30,1')->name('shares.store');

        Route::get('/spin', [V1\SpinController::class, 'show'])->name('spin.show');
        Route::post('/spin', [V1\SpinController::class, 'spin'])->middleware('throttle:10,1')->name('spin.spin');

        Route::post('/lightning-deals/{flashDeal}/remind', [V1\FlashDealController::class, 'remind'])->name('lightning-deals.remind');

        Route::get('/price-cuts', [V1\PriceCutController::class, 'index'])->name('price-cuts.index');
        Route::post('/price-cuts', [V1\PriceCutController::class, 'store'])->middleware('throttle:10,1')->name('price-cuts.store');
        Route::post('/price-cuts/{priceCut:token}/cut', [V1\PriceCutController::class, 'cut'])->middleware('throttle:10,1')->name('price-cuts.cut');

        Route::get('/farm', [V1\FarmController::class, 'show'])->name('farm.show');
        Route::post('/farm/plant', [V1\FarmController::class, 'plant'])->name('farm.plant');
        Route::post('/farm/friends/{code}/water', [V1\FarmController::class, 'waterFriend'])->middleware('throttle:20,1')->name('farm.water');

        Route::get('/trials', [V1\TrialController::class, 'index'])->name('trials.index');
        Route::post('/trials/{trialCampaign}/apply', [V1\TrialController::class, 'apply'])->middleware('throttle:10,1')->name('trials.apply');

        // Business buying (Part B)
        Route::get('/business', [V1\BusinessController::class, 'show'])->name('business.show');
        Route::post('/business', [V1\BusinessController::class, 'store'])->middleware('throttle:10,1')->name('business.store');
        Route::get('/rfqs', [V1\RfqController::class, 'index'])->name('rfqs.index');
        Route::post('/rfqs', [V1\RfqController::class, 'store'])->middleware('throttle:20,1')->name('rfqs.store');
        Route::get('/rfqs/{rfq}', [V1\RfqController::class, 'show'])->name('rfqs.show');
        Route::post('/quotes/{quote}/accept', [V1\RfqController::class, 'accept'])->middleware('throttle:checkout')->name('quotes.accept');
        Route::post('/orders/{order}/milestones/{milestone}/pay', [V1\MilestoneController::class, 'pay'])->middleware('throttle:checkout')->name('milestones.pay');
        Route::post('/orders/{order}/inspection', [V1\MilestoneController::class, 'inspection'])->middleware('throttle:checkout')->name('orders.inspection');
        Route::get('/payments/{payment}', [V1\MilestoneController::class, 'payment'])->name('payments.show');
        Route::post('/payments/{payment}/simulate', [V1\MilestoneController::class, 'simulate'])->name('payments.simulate');
        Route::get('/agent/inspections', [V1\AgentController::class, 'index'])->name('agent.inspections');
        Route::post('/agent/inspections/{inspection}/report', [V1\AgentController::class, 'report'])->middleware('throttle:20,1')->name('agent.report');
        Route::post('/assistant', [V1\AssistantController::class, 'ask'])->middleware('throttle:10,1')->name('assistant.ask');
        Route::post('/assistant/rfq', [V1\AssistantController::class, 'createRfq'])->middleware('throttle:10,1')->name('assistant.rfq');
        Route::get('/global/products', [V1\ImportController::class, 'products'])->name('global.products');
        Route::get('/global/orders', [V1\ImportController::class, 'orders'])->name('global.orders');
        Route::post('/global/orders', [V1\ImportController::class, 'store'])->middleware('throttle:checkout')->name('global.store');
        Route::post('/global/orders/{importOrder}/balance', [V1\ImportController::class, 'payBalance'])->middleware('throttle:checkout')->name('global.balance');

        // Photos and chat in the app
        Route::get('/problem-reasons', [V1\DisputeController::class, 'reasons'])->name('disputes.reasons');
        Route::post('/orders/{order}/problem', [V1\DisputeController::class, 'store'])->middleware('throttle:10,1')->name('disputes.store');
        Route::get('/return-reasons', [V1\ReturnController::class, 'reasons'])->name('returns.reasons');
        Route::post('/orders/{order}/return', [V1\ReturnController::class, 'store'])->middleware('throttle:10,1')->name('returns.store');
        Route::post('/seller/returns/{returnRequest}/{action}', [V1\ReturnController::class, 'act'])
            ->whereIn('action', ['accept', 'refuse', 'received'])->middleware('throttle:30,1')->name('seller.returns.act');
        Route::post('/trials/{trialCampaign}/review', [V1\TrialController::class, 'review'])->middleware('throttle:10,1')->name('trials.review');
        Route::get('/chat', [V1\ChatController::class, 'index'])->name('chat.index');
        Route::post('/chat/direct/{user}', [V1\ChatController::class, 'direct'])->whereNumber('user')->name('chat.direct');
        Route::get('/chat/{conversation}/messages', [V1\ChatController::class, 'messages'])->whereNumber('conversation')->name('chat.messages');
        Route::post('/chat/{conversation}/messages', [V1\ChatController::class, 'send'])->whereNumber('conversation')->middleware('throttle:chat-send')->name('chat.send');
        Route::post('/chat/{conversation}/read', [V1\ChatController::class, 'read'])->whereNumber('conversation')->name('chat.read');

        // Notifications
        Route::get('/notifications', [V1\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/counts', [V1\NotificationController::class, 'counts'])->name('notifications.counts');
        Route::post('/notifications/read', [V1\NotificationController::class, 'readAll'])->name('notifications.read');
        Route::post('/devices', [V1\NotificationController::class, 'registerDevice'])->middleware('throttle:20,1')->name('devices.store');
        Route::delete('/devices', [V1\NotificationController::class, 'forgetDevice'])->name('devices.destroy');

        // Saved addresses and location (location is only sent when the buyer chooses to)
        Route::get('/addresses', [V1\AddressController::class, 'index'])->name('addresses.index');
        Route::post('/addresses', [V1\AddressController::class, 'store'])->middleware('throttle:30,1')->name('addresses.store');
        Route::put('/addresses/{address}', [V1\AddressController::class, 'update'])->middleware('throttle:30,1')->name('addresses.update');
        Route::delete('/addresses/{address}', [V1\AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::get('/geo/delivery/{seller:slug}', [V1\GeoController::class, 'delivery'])->middleware('throttle:60,1')->name('geo.delivery');

        // Group buying: start, join, my groups
        Route::post('/group-buys/{campaign}/start', [V1\GroupBuyController::class, 'start'])->whereNumber('campaign')->middleware('throttle:checkout')->name('group-buys.start');
        Route::post('/groups/{group:token}/join', [V1\GroupBuyController::class, 'join'])->middleware('throttle:checkout')->name('groups.join');
        Route::get('/my-groups', [V1\GroupBuyController::class, 'mine'])->name('groups.mine');

        // Seller tools
        Route::prefix('seller')->name('seller.')->group(function () {
            Route::get('/dashboard', [V1\SellerController::class, 'dashboard'])->name('dashboard');
            Route::get('/orders', [V1\SellerController::class, 'orders'])->name('orders');
            Route::get('/orders/{order}', [V1\SellerController::class, 'order'])->name('orders.show');
            Route::post('/orders/{order}/{action}', [V1\SellerController::class, 'act'])->whereIn('action', ['accept', 'reject', 'ship', 'deliver'])->name('orders.act');
            Route::get('/products', [V1\SellerController::class, 'products'])->name('products');
            Route::post('/products', [V1\SellerController::class, 'saveProduct'])->middleware('throttle:30,1')->name('products.store');
            Route::post('/products/{product:slug}', [V1\SellerController::class, 'saveProduct'])->middleware('throttle:30,1')->name('products.update');
            Route::get('/earnings', [V1\SellerController::class, 'earnings'])->name('earnings');
            Route::post('/payouts', [V1\SellerController::class, 'payout'])->middleware('throttle:10,1')->name('payouts');
            Route::get('/rfqs', [V1\SellerController::class, 'rfqs'])->name('rfqs');
            Route::post('/rfqs/{rfq}/quote', [V1\SellerController::class, 'quote'])->middleware('throttle:30,1')->name('rfqs.quote');
            Route::get('/quotes', [V1\SellerController::class, 'quotes'])->name('quotes');
            Route::get('/locations', [V1\SellerController::class, 'locations'])->name('locations');
            Route::post('/locations', [V1\SellerController::class, 'saveLocation'])->middleware('throttle:30,1')->name('locations.store');
            Route::post('/locations/{location}', [V1\SellerController::class, 'saveLocation'])->middleware('throttle:30,1')->name('locations.update');
            Route::get('/group-offers', [V1\SellerController::class, 'groupOffers'])->name('group-offers');
            Route::post('/group-offers', [V1\SellerController::class, 'saveGroupOffer'])->middleware('throttle:30,1')->name('group-offers.store');
        });

        Route::get('/pass', [V1\PassController::class, 'show'])->name('pass.show');
        Route::post('/pass', [V1\PassController::class, 'store'])->middleware('throttle:checkout')->name('pass.store');
        Route::get('/pass/{membership}', [V1\PassController::class, 'status'])->name('pass.status');
        Route::post('/pass/{membership}/simulate', [V1\PassController::class, 'simulate'])->name('pass.simulate');
    });
});
