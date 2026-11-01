<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's national ID card, needed before an admin can approve a shop. The number is stored encrypted,
     * with a one-way fingerprint so the same card used for two shops can be spotted. The photos are on the
     * private disk and only admins who approve shops can open them.
     */
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->text('owner_id_number')->nullable()->after('business_registration_no');
            $table->string('owner_id_number_hash', 64)->nullable()->index()->after('owner_id_number');
            $table->string('owner_id_front_path')->nullable()->after('owner_id_number_hash');
            $table->string('owner_id_back_path')->nullable()->after('owner_id_front_path');
            $table->timestamp('owner_id_submitted_at')->nullable()->after('owner_id_back_path');
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropIndex(['owner_id_number_hash']);
            $table->dropColumn(['owner_id_number', 'owner_id_number_hash', 'owner_id_front_path', 'owner_id_back_path', 'owner_id_submitted_at']);
        });
    }
};
