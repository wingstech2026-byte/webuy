<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer protection (price protection, late-shipping coupon, genuine-goods guarantee), product videos,
     * and the product views that "For you" recommendations are built from.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('genuine_guarantee')->default(false)->after('condition'); // the seller promises genuine goods
            $table->string('video_path')->nullable()->after('genuine_guarantee');     // short video on the public disk
            $table->unsignedSmallInteger('video_seconds')->nullable()->after('video_path');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('genuine_guarantee')->default(false)->after('line_total'); // promised when it was bought
            $table->decimal('price_protection_amount', 12, 2)->default(0)->after('genuine_guarantee'); // coupons given so far
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('late_compensation', 12, 2)->default(0)->after('commission');
        });

        // One row per person and product, updated on each visit (only for logged-in shoppers).
        Schema::create('product_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_views');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('late_compensation');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['genuine_guarantee', 'price_protection_amount']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['genuine_guarantee', 'video_path', 'video_seconds']);
        });
    }
};
