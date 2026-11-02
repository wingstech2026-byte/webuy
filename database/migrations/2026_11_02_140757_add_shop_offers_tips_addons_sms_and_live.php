<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop offers paid by the shop (codes, "spend X save Y", "buy 2 get 1 free") and bundles; tips for the rider;
 * food add-ons (extra meat, extra pepper); ordering by SMS / USSD; and live selling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // spend | code | bogo
            $table->string('title', 80);
            $table->string('code', 20)->nullable(); // kind=code: what buyers type (upper case)
            $table->decimal('amount', 12, 2)->nullable(); // Le off ...
            $table->decimal('percent', 5, 2)->nullable(); // ... or a percentage of the shop's items
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete(); // kind=bogo
            $table->unsignedSmallInteger('buy_quantity')->nullable(); // kind=bogo: buy N, get 1 more free
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['seller_id', 'is_active']);
            $table->unique(['seller_id', 'code']);
        });

        // "Rice + oil + onions together for less": a fixed price when all the items are in the cart.
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unique(['bundle_id', 'product_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shop_discount', 12, 2)->default(0); // paid by the shop: lowers the total
            $table->string('offer_labels', 300)->nullable();     // "Spend Le 500, save Le 50 · Lunch bundle"
            $table->foreignId('shop_offer_id')->nullable()->constrained()->nullOnDelete(); // the code or spend offer used
            $table->decimal('rider_tip', 12, 2)->default(0);     // all for the WiBuy rider (or the shop when it delivers)
        });

        // Food add-ons: "Extra meat +Le 15". The buyer ticks any of them; each is added to the price of one dish.
        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('addon_key', 100)->default(''); // sorted add-on ids, "3,7" ("" for none)
            $table->unique(['cart_id', 'product_id', 'variant_id', 'addon_key']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id', 'variant_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('addons', 300)->nullable(); // "Extra meat, Extra pepper" (already in the unit price)
        });

        // Live selling: the shop streams on YouTube or Facebook; WiBuy shows it with comments and products to buy.
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->string('stream_url', 300);
            $table->string('status', 10)->default('scheduled')->index(); // scheduled | live | ended
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('pinned_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('live_session_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['live_session_id', 'product_id']);
        });

        Schema::create('live_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 200);
            $table->timestamps();
            $table->index(['live_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_comments');
        Schema::dropIfExists('live_session_products');
        Schema::dropIfExists('live_sessions');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('addons');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'product_id', 'variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id', 'variant_id', 'addon_key']);
            $table->dropColumn('addon_key');
        });

        Schema::dropIfExists('product_addons');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_offer_id');
            $table->dropColumn(['shop_discount', 'offer_labels', 'rider_tip']);
        });

        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('shop_offers');
    }
};
