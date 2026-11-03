<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Food reviews: taste, portion and packaging stars; and the shop's public reply to a review. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('taste')->nullable();
            $table->unsignedTinyInteger('portion')->nullable();
            $table->unsignedTinyInteger('packaging')->nullable();
            $table->text('seller_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
        });

        Schema::table('seller_reviews', function (Blueprint $table) {
            $table->text('seller_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_reviews', function (Blueprint $table) {
            $table->dropColumn(['seller_reply', 'replied_at']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['taste', 'portion', 'packaging', 'seller_reply', 'replied_at']);
        });
    }
};
