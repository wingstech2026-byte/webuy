<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which identity document the shop owner sent, and their nationality. Sierra Leoneans send a national ID
     * card or a passport; others send a passport, residence permit, their own national ID card or another
     * official ID.
     */
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->string('owner_id_type', 20)->nullable()->after('owner_id_number_hash'); // national_id | passport | residence_permit | other
            $table->string('owner_nationality', 60)->nullable()->after('owner_id_type');
        });

        // Documents sent before this was asked were Sierra Leone national ID cards.
        DB::table('sellers')->whereNotNull('owner_id_front_path')->update(['owner_id_type' => 'national_id', 'owner_nationality' => 'Sierra Leone']);
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['owner_id_type', 'owner_nationality']);
        });
    }
};
