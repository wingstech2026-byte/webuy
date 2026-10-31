<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('shop_name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('district', 40);
            $table->string('area')->nullable();
            $table->string('pickup_address');
            $table->string('payout_phone', 12);
            $table->string('payout_operator', 12); // ORANGE | AFRIMONEY
            $table->string('business_registration_no', 60)->nullable();
            $table->string('status', 12)->default('pending')->index();
            $table->boolean('is_verified')->default(false);
            $table->string('rejection_reason')->nullable();
            $table->boolean('offers_delivery')->default(true);
            $table->boolean('offers_pickup')->default(true);
            $table->decimal('commission_percent', 5, 2)->nullable(); // overrides the site-wide rate
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'district']);
        });

        // What the seller charges to deliver to each district.
        Schema::create('seller_delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('district', 40);
            $table->decimal('fee', 12, 2);
            $table->timestamps();

            $table->unique(['seller_id', 'district']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('emoji', 8)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2); // retail price per unit
            $table->decimal('compare_at_price', 12, 2)->nullable(); // "was" price
            $table->unsignedInteger('stock')->default(0); // units available (reserved units are already taken off)
            $table->string('unit', 40)->default('piece'); // piece, 50kg bag, carton ...
            $table->unsignedInteger('min_order_quantity')->default(1);
            $table->string('condition', 8)->default('new');
            $table->string('status', 12)->default('draft');
            $table->boolean('is_deal')->default(false);     // "Deals of the day"
            $table->boolean('is_featured')->default(false); // featured on the home page
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'price']);
            $table->index(['category_id', 'status']);
            $table->index(['is_deal', 'status']);
            $table->index(['is_featured', 'status']);
        });

        // Wholesale tiers: from min_quantity units the unit price is unit_price.
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->unique(['product_id', 'min_quantity']);
        });

        // Uploaded pictures (product gallery, shop logo, dispute photos). Processed on the queue.
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable');
            $table->string('collection', 20); // gallery | logo | dispute
            $table->string('original_path')->nullable(); // private upload, deleted once processed
            $table->string('path')->nullable();          // resized (max 1080px)
            $table->string('thumb_path')->nullable();    // small thumbnail
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 12)->default('processing'); // processing | ready | failed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // uploader
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id', 'collection', 'position'], 'images_lookup');
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('images');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('seller_delivery_areas');
        Schema::dropIfExists('sellers');
    }
};
