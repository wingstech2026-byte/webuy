<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Buyers follow shops (a feed of their news); and the reward when an invited friend's first order arrives. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'seller_id']);
            $table->index('seller_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Set on the invited person when their first order completed and both got the reward (once).
            $table->timestamp('referral_rewarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('referral_rewarded_at');
        });

        Schema::dropIfExists('shop_follows');
    }
};
