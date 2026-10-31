<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // support | direct
            $table->foreignId('owner_id')->nullable()->unique()->constrained('users')->cascadeOnDelete(); // support: one per user
            $table->string('direct_key', 24)->nullable()->unique(); // direct: "{lowId}-{highId}"
            $table->unsignedBigInteger('admin_last_read_message_id')->nullable(); // support: how far the team has read
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable(); // exact "read up to" marker
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system message
            $table->string('type', 10); // text | image | system | voice
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamp('deleted_at')->nullable(); // removed by an admin
            $table->timestamps();

            // "latest 30" and "older than X" both walk this index.
            $table->index(['conversation_id', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'reporter_id']);
            $table->index('resolved_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('chat_banned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chat_banned_at');
        });
        Schema::dropIfExists('message_reports');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
