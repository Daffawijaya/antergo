<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The drivers table lives outside the repo migrations (created directly
        // on the Supabase Postgres instance), so guard the alteration.
        if (Schema::hasTable('drivers') && ! Schema::hasColumn('drivers', 'avatar')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->text('avatar')->nullable()->after('nik');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('drivers') && Schema::hasColumn('drivers', 'avatar')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('avatar');
            });
        }
    }
};
