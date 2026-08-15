<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique('vehicles_driver_id_key');
            $table->index('driver_id', 'vehicles_driver_id_index');
            $table->text('image_path')->nullable();
        });

        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('file_path');
            $table->timestampsTz();
            $table->unique(['driver_id', 'type']);
            $table->index('type');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignId('active_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
        });

        DB::statement('UPDATE drivers SET active_vehicle_id = vehicles.id FROM vehicles WHERE vehicles.driver_id = drivers.id AND drivers.active_vehicle_id IS NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->jsonb('vehicle_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        DB::statement('UPDATE drivers SET active_vehicle_id = vehicles.id FROM vehicles WHERE vehicles.driver_id = drivers.id AND drivers.active_vehicle_id IS NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('vehicle_snapshot');
        });
        Schema::table('drivers', fn (Blueprint $table) => $table->dropConstrainedForeignId('active_vehicle_id'));
        Schema::dropIfExists('driver_documents');
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('image_path');
            $table->dropIndex('vehicles_driver_id_index');
        });

        // Restoring one-vehicle uniqueness is intentionally omitted because
        // multiple vehicles may exist after this migration has run.
    }
};
