<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titip_beli_items', function (Blueprint $table) {
            if (! Schema::hasColumn('titip_beli_items', 'price')) {
                $table->decimal('price', 15, 2)->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('titip_beli_items', function (Blueprint $table) {
            if (Schema::hasColumn('titip_beli_items', 'price')) {
                $table->dropColumn('price');
            }
        });
    }
};
