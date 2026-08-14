<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });

        DB::statement("ALTER TABLE user_roles ADD CONSTRAINT user_roles_role_check CHECK (role IN ('customer', 'driver', 'merchant', 'admin'))");

        DB::table('users')
            ->select(['id', 'role', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                DB::table('user_roles')->insertOrIgnore(
                    $users->map(fn ($user) => [
                        'user_id' => $user->id,
                        'role' => $user->role,
                        'created_at' => $user->created_at ?? now(),
                        'updated_at' => $user->updated_at ?? now(),
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
