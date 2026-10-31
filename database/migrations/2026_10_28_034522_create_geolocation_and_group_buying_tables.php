<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location-aware commerce (saved buyer addresses, seller shop/warehouse/pickup locations with delivery
 * radius and fees, delivery coordinates on orders), group buying, and an audit log of admin actions.
 * Coordinates are decimal degrees (6 decimals, about 10 cm); money stays decimal(12,2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40); // Home, Shop, Mum's house
            $table->string('recipient_name', 80);
            $table->string('recipient_phone', 12);
            $table->string('district', 40);
            $table->string('town', 80)->nullable();
            $table->string('neighbourhood', 80)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('landmark', 160)->nullable(); // "behind the mosque, blue gate"
            $table->string('directions', 300)->nullable();
            $table->decimal('latitude', 9, 6)->nullable(); // optional: GPS or a pin set on the map
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        Schema::create('seller_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('shop'); // shop | warehouse | pickup_point
            $table->string('public_name', 80);
            $table->string('public_address', 160)->nullable();
            $table->string('district', 40);
            $table->string('town', 80)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            // A home business: buyers only ever see an approximate point (about 1 km), never the house.
            $table->boolean('is_residence')->default(false);
            $table->string('verification_status', 12)->default('unverified'); // unverified | verified (checked by WiBuy)
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('pickup_enabled')->default(false);
            $table->string('pickup_instructions', 300)->nullable();
            $table->decimal('delivery_radius_km', 6, 2)->nullable(); // null: deliver by district only
            $table->decimal('base_fee', 12, 2)->default(0);
            $table->decimal('per_km_fee', 12, 2)->default(0);
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->string('delivery_days', 60)->nullable(); // "Mon-Sat"
            $table->string('delivery_window', 60)->nullable(); // "Same day if ordered before 12pm" (an estimate)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'district']);
        });

        // District zones (existing) get a minimum order and an estimated delivery window too.
        Schema::table('seller_delivery_areas', function (Blueprint $table) {
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->string('delivery_window', 60)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('saved_address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('landmark', 160)->nullable();
            $table->decimal('delivery_latitude', 9, 6)->nullable(); // private: seller and admin only
            $table->decimal('delivery_longitude', 9, 6)->nullable();
            $table->decimal('distance_km', 7, 2)->nullable(); // straight-line estimate used for the fee
        });

        Schema::create('group_buy_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->decimal('group_price', 12, 2); // per unit, shown next to the normal price
            $table->unsignedSmallInteger('min_group_size'); // paid members needed
            $table->unsignedSmallInteger('max_group_size')->nullable();
            $table->unsignedSmallInteger('max_quantity_each')->default(5);
            $table->unsignedSmallInteger('hours')->default(24); // time to fill a group
            $table->json('eligible_districts')->nullable(); // null: wherever the seller delivers
            $table->foreignId('collection_point_id')->nullable()->constrained('seller_locations')->nullOnDelete();
            $table->timestamp('ends_at')->nullable(); // no new groups after this
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        Schema::create('group_buys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_buy_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leader_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 16)->unique(); // invitation link
            $table->string('status', 10)->default('open'); // open | succeeded | failed
            $table->unsignedSmallInteger('paid_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('group_buy_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_buy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 10)->default('pending'); // pending | paid | cancelled | refunded
            $table->string('device_hash', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['group_buy_id', 'user_id']); // one place per person per group
            $table->index('order_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80); // route name, e.g. admin.sellers.approve
            $table->string('subject', 120)->nullable(); // e.g. seller:kamara-foods
            $table->json('details')->nullable(); // field names changed (never secret values)
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('group_buy_participants');
        Schema::dropIfExists('group_buys');
        Schema::dropIfExists('group_buy_campaigns');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saved_address_id');
            $table->dropColumn(['landmark', 'delivery_latitude', 'delivery_longitude', 'distance_km']);
        });
        Schema::table('seller_delivery_areas', fn (Blueprint $table) => $table->dropColumn(['min_order_value', 'delivery_window']));
        Schema::dropIfExists('seller_locations');
        Schema::dropIfExists('saved_addresses');
    }
};
