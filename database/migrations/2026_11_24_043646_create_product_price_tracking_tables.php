<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->timestamp('recorded_at');
            $table->index(['product_id', 'recorded_at']);
        });

        Schema::create('product_price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('target', 14, 2);
            $table->unique(['user_id', 'product_id']);
        });

        $recordedAt = now();
        DB::table('products')->select('id', 'price')->chunkById(500, function ($products) use ($recordedAt): void {
            DB::table('product_price_history')->insert($products->map(fn ($product) => [
                'product_id' => $product->id,
                'price' => $product->price,
                'recorded_at' => $recordedAt,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_alerts');
        Schema::dropIfExists('product_price_history');
    }
};
