<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers and admins sign in with a phone number + SMS code: no email, no password.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(); // asked on first login
            $table->string('phone', 12)->unique(); // 232 + 8 digits
            $table->boolean('is_admin')->default(false); // roles: buyer (default), seller (has an approved shop), admin
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
