<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WiBuy riders: a seller asks for a rider on a delivery order; a rider takes the job, picks the goods up
     * and hands them over with the buyer's code (or a photo as proof). One job per order.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_rider')->default(false)->after('is_agent');
        });

        Schema::create('delivery_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            // open | assigned | picked_up | delivered | cancelled
            $table->string('status', 20)->default('open')->index();
            $table->string('pickup_name', 120);
            $table->string('pickup_address', 200)->nullable();
            $table->decimal('pickup_latitude', 9, 6)->nullable();
            $table->decimal('pickup_longitude', 9, 6)->nullable();
            $table->string('dropoff_district', 40)->nullable()->index();
            $table->decimal('dropoff_latitude', 9, 6)->nullable();
            $table->decimal('dropoff_longitude', 9, 6)->nullable();
            $table->decimal('fee', 12, 2);
            $table->string('code_hash')->nullable(); // the buyer's handover code, hashed
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->string('proof', 10)->nullable(); // code | photo
            $table->string('proof_note', 300)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_jobs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_rider');
        });
    }
};
