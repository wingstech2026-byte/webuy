<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One checkout = one mobile money payment, covering one order per seller.
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total', 12, 2);
            $table->string('status', 16)->default('pending_payment')->index();
            $table->string('payer_phone', 12);
            $table->string('operator', 12);
            $table->timestamp('expires_at')->nullable()->index(); // how long the stock is held
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique(); // WB-XXXXXXXX
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending_payment');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0); // the rate at the time of the order
            $table->decimal('total', 12, 2);

            $table->string('delivery_method', 10); // delivery | pickup
            $table->string('recipient_name');
            $table->string('recipient_phone', 12);
            $table->string('district', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('buyer_note')->nullable();
            $table->string('delivery_note')->nullable(); // written by the seller
            $table->string('rejection_reason')->nullable();
            $table->string('refund_reason')->nullable();
            $table->string('status_before_dispute', 20)->nullable();

            $table->boolean('stock_reserved')->default(true);
            $table->timestamp('expires_at')->nullable();   // payment hold
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('accept_by')->nullable();    // seller must accept before this
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'created_at']);
            $table->index(['status', 'accept_by']);
            $table->index(['status', 'shipped_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title'); // snapshots: the product can change later
            $table->string('unit', 40);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->string('thumb_path')->nullable();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('requested')->index();
            $table->string('operator', 12);
            $table->string('phone', 12);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        // Every deposit, refund and payout that goes through the gateway.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->nullable()->constrained()->cascadeOnDelete(); // deposits
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();    // refunds
            $table->foreignId('payout_id')->nullable()->constrained()->cascadeOnDelete();   // payouts
            $table->string('kind', 10); // deposit | refund | payout
            $table->string('provider', 20); // fake | pawapay
            $table->uuid('provider_reference')->unique(); // depositId / refundId / payoutId
            $table->string('phone', 12);
            $table->string('operator', 12);
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('pending');
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // A seller's running balance: every credit and debit, newest last. Balance = sum(amount).
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // sale | payout | payout_reversal | adjustment
            $table->decimal('amount', 12, 2); // + credit, - debit
            $table->string('description');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['seller_id', 'id']);
            $table->unique(['order_id', 'type']); // an order can only be credited once
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete(); // once per purchased item
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'id']);
        });

        Schema::create('seller_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete(); // once per order
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'id']);
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 60);
            $table->text('description');
            $table->string('status', 16)->default('open')->index();
            $table->text('seller_response')->nullable();
            $table->timestamp('seller_responded_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('seller_reviews');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkouts');
    }
};
