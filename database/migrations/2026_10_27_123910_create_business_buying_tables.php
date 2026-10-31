<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part B business buying: verified business buyers, lead times and samples, RFQs with quotes that lock
 * the price, milestone payments, pre-delivery inspection, seller reliability score, the AI shopping
 * assistant log and WiBuy Global import orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('registration_no', 60);
            $table->string('district', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('status', 10)->default('pending')->index(); // pending | verified | rejected
            $table->string('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_agent')->default(false); // WiBuy inspection agent
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable(); // made to order: "Ready in X days"
            $table->decimal('sample_price', 12, 2)->nullable();          // one unit before a bulk order
        });

        Schema::table('price_tiers', function (Blueprint $table) {
            $table->boolean('business_only')->default(false); // only verified business buyers see this price
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->unsignedTinyInteger('reliability_score')->nullable(); // 0-100, last 90 days; null = new
            $table->timestamp('reliability_updated_at')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false);
        });

        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('unit', 40)->default('piece');
            $table->string('district', 40);
            $table->decimal('target_price', 12, 2)->nullable(); // per unit, optional
            $table->date('needed_by')->nullable();
            $table->string('status', 10)->default('open')->index(); // open | awarded | closed
            $table->string('source', 10)->default('buyer');          // buyer | assistant
            $table->timestamps();
        });

        Schema::create('rfq_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // what the seller offers
            $table->decimal('unit_price', 12, 2); // locked until valid_until
            $table->unsignedInteger('quantity');
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('valid_until');
            $table->string('status', 10)->default('submitted'); // submitted | accepted | declined
            $table->timestamps();

            $table->unique(['rfq_id', 'seller_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('rfq_quote_id')->nullable()->constrained('rfq_quotes')->nullOnDelete();
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->string('payment_plan', 10)->default('full'); // full | milestones
        });

        Schema::create('order_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('label', 60);
            $table->unsignedTinyInteger('percent');
            $table->decimal('amount', 12, 2);
            $table->string('status', 10)->default('pending'); // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'position']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('fee', 12, 2);
            $table->string('status', 12)->default('pending_payment'); // pending_payment | waiting | assigned | passed | failed
            $table->text('report')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });

        // Payments for order milestones, inspection fees and import orders ("charges").
        Schema::table('payments', function (Blueprint $table) {
            $table->nullableMorphs('payable');
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();
        });

        Schema::create('assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->json('understood')->nullable(); // what the assistant extracted
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('status', 10)->default('ok'); // ok | error
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('import_products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('origin', 60)->default('China');
            $table->string('unit', 40)->default('piece');
            $table->unsignedInteger('min_quantity')->default(1);
            $table->decimal('sea_price', 12, 2)->nullable(); // landed price per unit: goods + shipping + duties
            $table->unsignedSmallInteger('sea_days')->nullable();
            $table->decimal('air_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('air_days')->nullable();
            $table->unsignedTinyInteger('deposit_percent')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('import_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('shipping', 4); // sea | air
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('deposit_amount', 12, 2);
            $table->decimal('balance_amount', 12, 2);
            $table->string('status', 20)->default('awaiting_deposit');
            $table->date('estimated_arrival')->nullable();
            $table->timestamp('deposit_paid_at')->nullable();
            $table->timestamp('balance_paid_at')->nullable();
            $table->json('timeline')->nullable(); // status => time
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_orders');
        Schema::dropIfExists('import_products');
        Schema::dropIfExists('assistant_logs');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_id');
            $table->dropMorphs('payable');
        });
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('order_milestones');
        Schema::table('checkouts', fn (Blueprint $table) => $table->dropColumn('payment_plan'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('rfq_quote_id'));
        Schema::dropIfExists('rfq_quotes');
        Schema::dropIfExists('rfqs');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('is_sample'));
        Schema::table('sellers', fn (Blueprint $table) => $table->dropColumn(['reliability_score', 'reliability_updated_at']));
        Schema::table('price_tiers', fn (Blueprint $table) => $table->dropColumn('business_only'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['lead_time_days', 'sample_price']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_agent'));
        Schema::dropIfExists('business_profiles');
    }
};
