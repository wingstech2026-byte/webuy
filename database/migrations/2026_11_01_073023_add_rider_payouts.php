<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rider payouts: a rider earns the delivery fee when the order completes and withdraws it like a seller.
     * Ledger rows and payouts belong to a seller or a rider, so seller_id may now be empty.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->change();
            $table->foreignId('rider_id')->nullable()->after('seller_id')->constrained('users')->cascadeOnDelete();
            $table->index(['rider_id', 'id']);
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->change();
            $table->foreignId('rider_id')->nullable()->after('seller_id')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rider_id');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['rider_id']);
            $table->dropIndex(['rider_id', 'id']);
            $table->dropColumn('rider_id');
        });
    }
};
