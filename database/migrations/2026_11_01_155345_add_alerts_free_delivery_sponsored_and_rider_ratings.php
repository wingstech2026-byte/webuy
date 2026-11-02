<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wishlist alerts (price drop, back in stock), free delivery from an amount, sponsored products, and buyers
     * rating the rider who delivered.
     */
    public function up(): void
    {
        // The price and stock the person last heard about, so each drop or restock is told once.
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->decimal('alert_price', 12, 2)->nullable()->after('product_id');
            $table->boolean('alert_in_stock')->default(true)->after('alert_price');
        });
        DB::table('wishlist_items')->update([
            'alert_price' => DB::raw('(select price from products where products.id = wishlist_items.product_id)'),
            'alert_in_stock' => DB::raw('(select case when stock > 0 then 1 else 0 end from products where products.id = wishlist_items.product_id)'),
        ]);

        Schema::table('sellers', function (Blueprint $table) {
            $table->decimal('free_delivery_from', 12, 2)->nullable()->after('offers_pickup'); // the shop pays delivery above this
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shop_paid_delivery', 12, 2)->default(0)->after('delivery_fee'); // free delivery: what the shop pays a rider
        });

        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('sponsored_until')->nullable()->index()->after('is_featured');
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('proof_note'); // the buyer's stars for the rider
            $table->string('rating_comment', 300)->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_comment']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sponsored_until']);
            $table->dropColumn('sponsored_until');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shop_paid_delivery');
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('free_delivery_from');
        });

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['alert_price', 'alert_in_stock']);
        });
    }
};
