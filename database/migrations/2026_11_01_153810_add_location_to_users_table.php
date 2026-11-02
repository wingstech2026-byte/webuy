<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every buyer gives their name and where they live (district and town or area) when they sign up; the phone
     * number is already checked by SMS code. Asked once of existing accounts that miss them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('district', 40)->nullable()->after('name');
            $table->string('area', 80)->nullable()->after('district'); // town or neighbourhood
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['district', 'area']);
        });
    }
};
