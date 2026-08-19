<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orders: driver queries filter by driver_id + status + type frequently
        Schema::table('orders', function (Blueprint $table) {
            $table->index('driver_id');
            $table->index('status');
            $table->index('type');
            $table->index(['driver_id', 'status']);
            $table->index(['driver_id', 'type', 'status']);
            $table->index(['status', 'type', 'driver_id']);
        });

        // Chat messages: conversations query uses order_id, sender_id, read_at
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('sender_id');
            $table->index('read_at');
            $table->index(['sender_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['driver_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['type']);
            $table->dropIndex(['driver_id', 'status']);
            $table->dropIndex(['driver_id', 'type', 'status']);
            $table->dropIndex(['status', 'type', 'driver_id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['sender_id']);
            $table->dropIndex(['read_at']);
            $table->dropIndex(['sender_id', 'read_at']);
        });
    }
};
