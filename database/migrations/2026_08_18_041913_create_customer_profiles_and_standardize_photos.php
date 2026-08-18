<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create customer_profiles
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('photo_url')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        // 2. Standardize drivers (ensure it has photo_url instead of avatar)
        Schema::table('drivers', function (Blueprint $table) {
            $table->renameColumn('avatar', 'photo_url');
        });

        // 3. Standardize merchants (ensure it has photo_url instead of logo)
        Schema::table('merchants', function (Blueprint $table) {
            $table->renameColumn('logo', 'photo_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->renameColumn('photo_url', 'logo');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->renameColumn('photo_url', 'avatar');
        });

        Schema::dropIfExists('customer_profiles');
    }
};
