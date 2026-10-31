<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Returns: the buyer sends some or all items back after delivery, before the order completes.
     * One return per order. Money stays with WiBuy until the seller has the items back.
     */
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_id')->constrained();
            $table->string('reason', 30);
            $table->text('description');
            $table->json('items'); // [{order_item_id, quantity}]
            $table->boolean('is_full');
            $table->decimal('returned_value', 12, 2); // the returned goods at their order price (seller side)
            $table->decimal('refund_amount', 12, 2);  // what goes back to the buyer's mobile money
            // requested | accepted | received | escalated | cancelled
            $table->string('status', 20)->default('requested')->index();
            $table->text('return_instructions')->nullable();
            $table->text('seller_note')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('send_back_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Goods returned and refunded: not paid to the seller when the order completes.
            $table->decimal('returned_value', 12, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('returned_value');
        });
        Schema::dropIfExists('return_requests');
    }
};
