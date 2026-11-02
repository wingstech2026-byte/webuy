<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instant food delivery around offices: food shops with opening hours and cooking time, orders delivered in
 * 30-60 minutes (or at a chosen time) by the nearest available WiBuy rider, office team orders, and office
 * addresses (building and floor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->boolean('is_instant')->default(false)->index(); // cooked food / quick goods delivered now
            $table->string('opens_at', 5)->nullable(); // "08:00" (empty: always open)
            $table->string('closes_at', 5)->nullable(); // "20:00"
            $table->unsignedSmallInteger('prep_minutes')->default(20);
        });

        // Office team orders: one person opens it, colleagues add their own meals and pay their own share.
        Schema::create('team_orders', function (Blueprint $table) {
            $table->id();
            $table->string('token', 16)->unique();
            $table->foreignId('leader_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->timestamp('deliver_at')->nullable(); // null: as soon as it is cooked
            $table->timestamp('closes_at'); // colleagues can join until then
            $table->string('recipient_name', 80);
            $table->string('recipient_phone', 12);
            $table->string('district', 40);
            $table->string('address', 200);
            $table->string('landmark', 160)->nullable();
            $table->string('building', 80)->nullable();
            $table->string('floor', 20)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('status', 12)->default('open')->index(); // open | closed
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_instant')->default(false);
            $table->timestamp('deliver_at')->nullable(); // a scheduled delivery time ("1:00 pm at the office")
            $table->timestamp('food_ready_at')->nullable();
            $table->timestamp('cook_reminded_at')->nullable();
            $table->foreignId('team_order_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->boolean('is_instant')->default(false); // shown first to riders
        });

        // A rider says "I'm available": one point at that moment, forgotten after a few hours (never tracked).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('rider_available_until')->nullable();
            $table->decimal('rider_available_lat', 9, 6)->nullable();
            $table->decimal('rider_available_lng', 9, 6)->nullable();
        });

        Schema::table('saved_addresses', function (Blueprint $table) {
            $table->string('building', 80)->nullable();
            $table->string('floor', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saved_addresses', function (Blueprint $table) {
            $table->dropColumn(['building', 'floor']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rider_available_until', 'rider_available_lat', 'rider_available_lng']);
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropColumn('is_instant');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_order_id');
            $table->dropColumn(['is_instant', 'deliver_at', 'food_ready_at', 'cook_reminded_at']);
        });

        Schema::dropIfExists('team_orders');

        Schema::table('sellers', function (Blueprint $table) {
            $table->dropIndex(['is_instant']);
            $table->dropColumn(['is_instant', 'opens_at', 'closes_at', 'prep_minutes']);
        });
    }
};
