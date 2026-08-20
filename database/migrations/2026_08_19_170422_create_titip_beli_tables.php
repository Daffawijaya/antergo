<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add titip_beli fields to existing orders table
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'advance_amount')) {
                $table->decimal('advance_amount', 15, 2)->nullable()->after('total_price');
            }
            if (! Schema::hasColumn('orders', 'driver_note')) {
                $table->text('driver_note')->nullable()->after('notes');
            }
        });

        // Purchase locations for titip beli orders
        Schema::create('titip_beli_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('place_name', 255);
            $table->string('address', 500);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();

            $table->index(['order_id', 'sequence']);
        });

        // Items per purchase location
        Schema::create('titip_beli_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('titip_beli_locations')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('quantity', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titip_beli_items');
        Schema::dropIfExists('titip_beli_locations');

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'advance_amount')) {
                $table->dropColumn('advance_amount');
            }
            if (Schema::hasColumn('orders', 'driver_note')) {
                $table->dropColumn('driver_note');
            }
        });
    }
};
