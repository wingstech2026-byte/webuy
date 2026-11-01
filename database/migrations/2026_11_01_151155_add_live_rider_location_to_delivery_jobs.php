<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live tracking of a rider who is carrying an order. Only the LATEST position is kept (no route history),
     * only while the parcel is with the rider, and it is cleared when the delivery ends.
     */
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->decimal('rider_latitude', 9, 6)->nullable()->after('dropoff_longitude');
            $table->decimal('rider_longitude', 9, 6)->nullable()->after('rider_latitude');
            $table->timestamp('rider_located_at')->nullable()->after('rider_longitude');
            $table->unsignedTinyInteger('notified_km')->nullable()->after('rider_located_at'); // last "X km away" message sent
        });
    }

    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropColumn(['rider_latitude', 'rider_longitude', 'rider_located_at', 'notified_km']);
        });
    }
};
