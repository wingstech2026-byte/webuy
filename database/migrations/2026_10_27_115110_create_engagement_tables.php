<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part A engagement: coupons, WiBuy Points, sharing and referrals, check-in, spin wheel, lightning
 * deals, "Help me cut the price", WiBuy Farm, free trials, WiBuy Pass and deferred app links.
 * Rewards are always coupons or points (never cash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('points_balance')->default(0);
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signup_channel', 8)->nullable(); // web | app
        });

        // Phones and browsers seen for each account, to stop one person cutting or referring with many accounts.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_hash', 64)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source', 20); // welcome | welcome_bonus | app_install | spin | farm | price_cut | trial | points | admin
            $table->decimal('amount', 12, 2)->nullable();  // fixed Le off ...
            $table->decimal('percent', 5, 2)->nullable();  // ... or a percentage of the items
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete(); // only for this product (one unit)
            $table->boolean('app_only')->default(false);
            $table->string('status', 10)->default('available')->index(); // locked | available | reserved | used | expired
            $table->string('unlock_hint')->nullable();
            $table->foreignId('checkout_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_given', 12, 2)->nullable(); // what it really cost WiBuy
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 8)->default('web');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->default(0); // paid by WiBuy (coupons, WiBuy Pass free delivery); the seller still gets the full total
            $table->string('gift_note')->nullable();       // e.g. a WiBuy Farm prize delivered with this order
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('points'); // + earned, - spent
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 16)->unique();
            $table->string('kind', 12); // app | product | price_cut | farm
            $table->string('target')->nullable(); // product slug, price cut token, farm code
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_device_hash', 64)->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedSmallInteger('streak');
            $table->unsignedInteger('points');
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });

        Schema::create('spin_prizes', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);
            $table->string('type', 8); // points | coupon
            $table->unsignedInteger('points')->default(0);
            $table->decimal('coupon_amount', 12, 2)->nullable();
            $table->decimal('coupon_min_spend', 12, 2)->default(0);
            $table->unsignedSmallInteger('coupon_days')->default(7);
            $table->unsignedInteger('weight')->default(1); // odds = weight / total weight, shown to users
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('spins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spin_prize_id')->nullable()->constrained()->nullOnDelete();
            $table->date('day');
            $table->string('source', 8); // free | share | pass
            $table->string('prize_label', 60);
            $table->timestamps();

            $table->index(['user_id', 'day']);
        });

        Schema::create('flash_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('deal_price', 12, 2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('max_per_order')->default(5); // the deal price applies up to this quantity
            $table->unsignedInteger('quantity_limit')->nullable(); // units sold at the deal price, then it ends
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
            $table->index(['product_id', 'ends_at']);
        });

        Schema::create('flash_deal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['flash_deal_id', 'user_id']);
        });

        Schema::create('price_cut_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('floor_price', 12, 2);
            $table->unsignedSmallInteger('hours')->default(24);
            $table->decimal('min_cut', 12, 2);
            $table->decimal('max_cut', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamp('ends_at')->nullable(); // no new cuts after this
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        Schema::create('price_cuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_cut_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 16)->unique();
            $table->decimal('start_price', 12, 2);
            $table->decimal('current_price', 12, 2);
            $table->string('status', 10)->default('active'); // active | reached | expired | used
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('price_cut_helpers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_cut_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_cut_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('helper_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_hash', 64)->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['price_cut_campaign_id', 'helper_id']); // one cut per friend per product
        });

        Schema::create('farm_missions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique(); // check_in | browse_deals | share | order | review | water_friend
            $table->string('label', 80);
            $table->unsignedSmallInteger('water');
            $table->unsignedSmallInteger('daily_limit')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('farm_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('plant', 10); // rice | mango
            $table->string('label', 80);
            $table->string('type', 8); // coupon | gift
            $table->decimal('coupon_amount', 12, 2)->nullable();
            $table->decimal('coupon_min_spend', 12, 2)->default(0);
            $table->string('gift_description')->nullable(); // "A 1kg bag of rice delivered with your next order"
            $table->decimal('cost', 12, 2)->default(0);       // what one prize costs WiBuy
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique(); // for "water my plant" links
            $table->string('plant', 10);
            $table->unsignedInteger('water')->default(0);
            $table->unsignedInteger('target');
            $table->unsignedSmallInteger('harvests')->default(0);
            $table->timestamps();
        });

        Schema::create('farm_waterings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('mission_key', 20);
            $table->unsignedSmallInteger('water');
            $table->date('day');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_id', 'mission_key', 'day']);
            $table->index(['farm_id', 'id']);
        });

        Schema::create('reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_reward_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 8);
            $table->string('description');
            $table->decimal('cost', 12, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('pending'); // pending | attached | fulfilled
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('trial_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->decimal('trial_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('slots');
            $table->timestamp('apply_until');
            $table->unsignedSmallInteger('review_days')->default(7);
            $table->string('status', 8)->default('open'); // open | closed
            $table->timestamps();
        });

        Schema::create('trial_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trial_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300)->nullable();
            $table->string('status', 10)->default('applied'); // applied | selected | rejected | reviewed | overdue
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('review_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamps();

            $table->unique(['trial_campaign_id', 'user_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('is_free_trial')->default(false);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('member_price', 12, 2)->nullable(); // WiBuy Pass price, set by the seller
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->string('status', 10)->default('pending'); // pending | active | failed
            $table->string('payer_phone', 12);
            $table->string('operator', 12);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ends_at']);
            $table->index(['status', 'ends_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // "Open this product after installing the app": remembered for a short time per browser fingerprint.
        Schema::create('deferred_links', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->index();
            $table->string('path');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });

        $this->insertDefaults();
    }

    /** Starting values so the features work at once. The admin changes all of them in /admin/rewards. */
    private function insertDefaults(): void
    {
        $now = now();

        $missions = [
            ['check_in', 'Daily check-in', 10, 1],
            ['browse_deals', "Browse today's lightning deals", 5, 1],
            ['share', 'Share WiBuy with a friend (they open the link)', 10, 3],
            ['order', 'Make an order', 30, 2],
            ['review', 'Write a review', 15, 2],
            ['water_friend', "Water a friend's plant", 5, 5],
        ];

        foreach ($missions as $i => [$key, $label, $water, $limit]) {
            DB::table('farm_missions')->insert(['key' => $key, 'label' => $label, 'water' => $water, 'daily_limit' => $limit, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('farm_rewards')->insert([
            ['plant' => 'rice', 'label' => 'Free 1kg bag of rice', 'type' => 'gift', 'coupon_amount' => null, 'coupon_min_spend' => 0, 'gift_description' => 'A 1kg bag of rice delivered with your next order', 'cost' => '30.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['plant' => 'mango', 'label' => 'Le 50 coupon', 'type' => 'coupon', 'coupon_amount' => '50.00', 'coupon_min_spend' => '150.00', 'gift_description' => null, 'cost' => '50.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $prizes = [
            ['10 points', 'points', 10, null, 0, 40],
            ['25 points', 'points', 25, null, 0, 25],
            ['50 points', 'points', 50, null, 0, 12],
            ['Le 5 coupon', 'coupon', 0, '5.00', '50.00', 15],
            ['Le 10 coupon', 'coupon', 0, '10.00', '100.00', 6],
            ['Le 25 coupon', 'coupon', 0, '25.00', '200.00', 2],
        ];

        foreach ($prizes as $i => [$label, $type, $points, $amount, $minSpend, $weight]) {
            DB::table('spin_prizes')->insert(['label' => $label, 'type' => $type, 'points' => $points, 'coupon_amount' => $amount, 'coupon_min_spend' => $minSpend, 'coupon_days' => 7, 'weight' => $weight, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deferred_links');
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('membership_id'));
        Schema::dropIfExists('memberships');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('member_price'));
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn('is_free_trial'));
        Schema::dropIfExists('trial_applications');
        Schema::dropIfExists('trial_campaigns');
        Schema::dropIfExists('reward_claims');
        Schema::dropIfExists('farm_waterings');
        Schema::dropIfExists('farms');
        Schema::dropIfExists('farm_rewards');
        Schema::dropIfExists('farm_missions');
        Schema::dropIfExists('price_cut_helpers');
        Schema::dropIfExists('price_cuts');
        Schema::dropIfExists('price_cut_campaigns');
        Schema::dropIfExists('flash_deal_reminders');
        Schema::dropIfExists('flash_deals');
        Schema::dropIfExists('spins');
        Schema::dropIfExists('spin_prizes');
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('shares');
        Schema::dropIfExists('point_transactions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['discount', 'gift_note']));
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['discount', 'channel']);
        });
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('user_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['points_balance', 'referral_code', 'signup_channel']);
        });
    }
};
