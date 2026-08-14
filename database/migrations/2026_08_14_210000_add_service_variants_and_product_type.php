<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'service_variant')) {
                $table->string('service_variant', 30)->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('orders', 'vehicle_type')) {
                $table->string('vehicle_type', 20)->nullable()->after('service_variant')->index();
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type', 20)->default('food')->after('merchant_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'product_type')) {
                $table->dropColumn('product_type');
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_values(array_filter(['service_variant', 'vehicle_type'], fn (string $column) => Schema::hasColumn('orders', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
