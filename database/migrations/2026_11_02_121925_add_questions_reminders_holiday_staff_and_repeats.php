<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product questions and answers, cart reminders, low-stock alerts, holiday mode, shop staff and
     * "order again" reminders.
     */
    public function up(): void
    {
        // A buyer asks on the product page; the shop's answer stays there for everyone.
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->string('answer', 1000)->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->boolean('is_hidden')->default(false); // hidden by the shop or an admin
            $table->timestamps();
            $table->index(['product_id', 'answered_at']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('user_id'); // "you left items in your cart" (once per change)
        });

        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('low_stock_notified_at')->nullable()->after('stock'); // told the shop once; cleared on restock
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->date('holiday_until')->nullable()->after('free_delivery_from'); // closed: products show, cannot be ordered
            $table->string('holiday_message', 200)->nullable()->after('holiday_until');
        });

        // Helpers who run orders and products for a shop; they never see or move its money.
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); // one shop per helper
            $table->timestamps();
        });

        // "Remind me to order this again every N days" (the buyer still pays each time).
        Schema::create('order_repeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('every_days');
            $table->timestamp('next_at')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_repeats');
        Schema::dropIfExists('seller_staff');

        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['holiday_until', 'holiday_message']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('low_stock_notified_at');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });

        Schema::dropIfExists('product_questions');
    }
};
