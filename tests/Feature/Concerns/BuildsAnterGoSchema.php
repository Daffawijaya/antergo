<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsAnterGoSchema
{
    protected function buildAnterGoSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->enum('role', ['customer', 'driver', 'merchant', 'admin'])->default('customer');
            $table->text('avatar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('nik')->unique();
            $table->string('license_number')->unique();
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->boolean('is_online')->default(false);
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('total_completed_orders')->default(0);
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('type', ['motorcycle', 'car']);
            $table->string('brand');
            $table->string('model')->nullable();
            $table->string('plate_number')->unique();
            $table->string('color')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('heading', 5, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->timestamp('updated_at');
        });

        Schema::create('merchant_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('merchant_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('phone');
            $table->text('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('logo')->nullable();
            $table->boolean('is_open')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->text('image')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['ride', 'send', 'food'])->default('ride');
            $table->text('pickup_address');
            $table->decimal('pickup_latitude', 10, 7);
            $table->decimal('pickup_longitude', 10, 7);
            $table->text('destination_address');
            $table->decimal('destination_latitude', 10, 7);
            $table->decimal('destination_longitude', 10, 7);
            $table->decimal('distance', 10, 2)->nullable();
            $table->integer('estimated_duration')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('service_fee', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->enum('payment_method', ['cash', 'qris', 'ewallet', 'gateway'])->default('cash');
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->enum('status', [
                'pending', 'searching_driver', 'driver_assigned', 'driver_arriving',
                'driver_arrived', 'merchant_confirmed', 'preparing', 'ready_for_pickup',
                'picked_up', 'in_progress', 'delivering', 'completed', 'cancelled',
            ])->default('pending');
            $table->text('notes')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('method', ['cash', 'qris', 'ewallet', 'gateway']);
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->decimal('amount', 15, 2);
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
}
