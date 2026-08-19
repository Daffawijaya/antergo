<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drivers: frequently queried by user_id
        Schema::table('drivers', function (Blueprint $table) {
            $table->index('user_id');
            $table->index(['status', 'is_online']);
        });

        // Driver locations: queried by driver_id for active driver lookups
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->index('driver_id');
        });

        // Orders: compound index for available orders query
        // Covers: status + type + driver_id + pickup_latitude/pickup_longitude
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'type', 'driver_id']);
            $table->index('pickup_latitude');
            $table->index('pickup_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status', 'is_online']);
        });

        Schema::table('driver_locations', function (Blueprint $table) {
            $table->dropIndex(['driver_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'type', 'driver_id']);
            $table->dropIndex('pickup_latitude');
            $table->dropIndex('pickup_longitude');
        });
    }
};
