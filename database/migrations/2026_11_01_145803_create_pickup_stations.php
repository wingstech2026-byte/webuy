<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WiBuy pickup stations, run by partner shops. Sellers drop parcels at the station nearest to them; riders
     * carry them to another station or to the buyer's door; buyers collect at a station with a code.
     */
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); // the partner who runs it
            $table->string('name', 120);
            $table->string('address', 200);
            $table->string('district', 40)->index();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('opening_hours', 120)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('drop_station_id')->nullable()->after('delivery_method')->constrained('stations')->nullOnDelete();
            $table->foreignId('collect_station_id')->nullable()->after('drop_station_id')->constrained('stations')->nullOnDelete();
            $table->timestamp('at_drop_station_at')->nullable()->after('collect_station_id');     // the shop handed it in
            $table->timestamp('at_collect_station_at')->nullable()->after('at_drop_station_at');  // ready for the buyer
            $table->string('collect_code_hash')->nullable()->after('at_collect_station_at');      // the buyer's code, hashed
            $table->unsignedTinyInteger('collect_code_attempts')->default(0)->after('collect_code_hash');
            $table->timestamp('station_fees_credited_at')->nullable()->after('collect_code_attempts');
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            // door: shop -> buyer (the seller asked for a rider); transfer: station -> station; station_door: station -> buyer
            $table->string('kind', 20)->default('door')->after('status');
            $table->foreignId('pickup_station_id')->nullable()->after('kind')->constrained('stations')->nullOnDelete();
            $table->foreignId('dropoff_station_id')->nullable()->after('pickup_station_id')->constrained('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dropoff_station_id');
            $table->dropConstrainedForeignId('pickup_station_id');
            $table->dropColumn('kind');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collect_station_id');
            $table->dropConstrainedForeignId('drop_station_id');
            $table->dropColumn(['at_drop_station_at', 'at_collect_station_at', 'collect_code_hash', 'collect_code_attempts', 'station_fees_credited_at']);
        });

        Schema::dropIfExists('stations');
    }
};
