<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
        Http::fake();
    }

    public function test_every_admin_endpoint_rejects_non_admin_users(): void
    {
        Sanctum::actingAs($this->user('customer', 'non-admin'));
        foreach (['dashboard', 'users', 'drivers', 'merchants', 'orders'] as $path) {
            $this->getJson("/api/admin/{$path}")->assertForbidden();
        }
    }

    public function test_dashboard_returns_valid_operational_metrics(): void
    {
        $this->actingAsAdmin();
        $customer = $this->user('customer', 'customer');
        [$driverUser] = $this->driver('pending', 'pending');
        [$approvedUser] = $this->driver('approved', 'approved');
        [, $merchant] = $this->merchant(true, 'active');
        $this->order(Order::TYPE_RIDE, Order::STATUS_COMPLETED, $customer, paymentStatus: 'paid');
        $this->order(Order::TYPE_SEND, Order::STATUS_CANCELLED, $driverUser);
        $this->order(Order::TYPE_FOOD, Order::STATUS_COMPLETED, $approvedUser, $merchant);

        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('metrics.total_customers', 3)
            ->assertJsonPath('metrics.total_drivers', 2)
            ->assertJsonPath('metrics.pending_drivers', 1)
            ->assertJsonPath('metrics.approved_drivers', 1)
            ->assertJsonPath('metrics.total_ride', 1)
            ->assertJsonPath('metrics.total_food', 1)
            ->assertJsonPath('metrics.total_send', 1)
            ->assertJsonPath('metrics.completed_orders', 2)
            ->assertJsonPath('metrics.cancelled_orders', 1)
            ->assertJsonPath('metrics.total_gmv', 40000)
            ->assertJsonCount(3, 'recent_orders');
    }

    public function test_user_search_pagination_detail_and_status_do_not_expose_sensitive_fields(): void
    {
        $admin = $this->actingAsAdmin();
        $user = $this->user('customer', 'searchable');

        $this->getJson('/api/admin/users?search=searchable&per_page=1')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.roles.0', 'customer')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.tokens')
            ->assertJsonMissingPath('data.0.push_device_tokens');
        $this->getJson("/api/admin/users/{$user->id}")->assertOk()
            ->assertJsonMissingPath('user.password');
        $this->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => false])->assertOk();
        $this->assertFalse($user->fresh()->is_active);
        $this->patchJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])->assertUnprocessable();
    }

    public function test_driver_approve_is_idempotent_and_sends_notification(): void
    {
        $this->actingAsAdmin();
        [$user, $driver] = $this->driver('pending', 'approval');

        $this->patchJson("/api/admin/drivers/{$driver->id}/status", ['status' => 'approved'])->assertOk();
        $this->patchJson("/api/admin/drivers/{$driver->id}/status", ['status' => 'approved'])->assertOk();
        $this->assertSame('approved', $driver->fresh()->status);
        $this->assertTrue($user->hasRole(UserRole::DRIVER));
        $this->assertSame(2, $user->roles()->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'driver_approved']);
    }

    public function test_admin_can_reject_suspend_and_reapprove_driver(): void
    {
        $this->actingAsAdmin();
        [, $driver] = $this->driver('pending', 'status');
        foreach (['rejected', 'approved', 'suspended', 'approved'] as $status) {
            $this->patchJson("/api/admin/drivers/{$driver->id}/status", compact('status'))
                ->assertOk()->assertJsonPath('driver.status', $status);
        }
        $this->assertFalse($driver->fresh()->is_online);
    }

    public function test_admin_can_activate_and_deactivate_merchant_with_notifications(): void
    {
        $this->actingAsAdmin();
        [$user, $merchant] = $this->merchant(true, 'control');

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('merchant.is_active', false);
        $this->assertFalse($merchant->fresh()->is_open);
        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('merchant.is_active', true);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'merchant_deactivated']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'merchant_activated']);
    }

    public function test_order_filters_and_ride_food_send_details_are_read_only(): void
    {
        $this->actingAsAdmin();
        $customer = $this->user('customer', 'orders');
        [, $merchant] = $this->merchant(true, 'orders');
        $ride = $this->order(Order::TYPE_RIDE, Order::STATUS_COMPLETED, $customer, paymentStatus: 'paid');
        $food = $this->order(Order::TYPE_FOOD, Order::STATUS_PENDING, $customer, $merchant);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'Food', 'price' => 10000, 'stock' => 3]);
        $food->items()->create(['product_id' => $product->id, 'product_name' => 'Food', 'price' => 10000, 'quantity' => 1, 'subtotal' => 10000]);
        $send = $this->order(Order::TYPE_SEND, Order::STATUS_SEARCHING_DRIVER, $customer, notes: json_encode([
            'item_name' => 'Dokumen', 'recipient_name' => 'Budi', 'recipient_phone' => '0812', 'notes' => 'Aman',
        ]));

        $this->getJson('/api/admin/orders?type=send&payment_status=pending')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $send->id);
        $this->getJson("/api/admin/orders/{$ride->id}")->assertOk()->assertJsonPath('order.type', 'ride');
        $this->getJson("/api/admin/orders/{$food->id}")->assertOk()->assertJsonPath('order.items.0.product_name', 'Food');
        $this->getJson("/api/admin/orders/{$send->id}")->assertOk()
            ->assertJsonPath('order.send_details.item_name', 'Dokumen')
            ->assertJsonPath('order.send_details.recipient_name', 'Budi');
    }

    private function actingAsAdmin(): User
    {
        $user = $this->user('admin', 'admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function user(string $role, string $suffix): User
    {
        $user = User::create([
            'name' => ucfirst($suffix), 'email' => "{$suffix}@example.com", 'phone' => '08'.substr(md5($suffix), 0, 10),
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $user->addRole($role);

        return $user;
    }

    private function driver(string $status, string $suffix): array
    {
        $user = $this->user('customer', "driver-{$suffix}");
        $driver = Driver::create([
            'user_id' => $user->id, 'nik' => "NIK-{$suffix}", 'license_number' => "SIM-{$suffix}",
            'status' => $status, 'is_online' => false,
        ]);

        $user->setRawAttributes(array_merge($user->getAttributes(), ['avatar' => 'avatar.jpg']));
        $user->save();
        $vehicle = $driver->vehicles()->create([
            'type' => 'motorcycle', 'brand' => 'Honda', 'model' => 'Beat',
            'plate_number' => "B-{$suffix}", 'color' => 'Black', 'image_path' => 'vehicle.jpg',
        ]);
        $driver->update(['active_vehicle_id' => $vehicle->id]);
        $driver->documents()->createMany([
            ['type' => 'ktp', 'file_path' => 'ktp.jpg'],
            ['type' => 'sim_c', 'file_path' => 'sim-c.jpg'],
        ]);

        return [$user, $driver];
    }

    private function merchant(bool $active, string $suffix): array
    {
        $user = $this->user('merchant', "merchant-{$suffix}");
        $category = MerchantCategory::create(['name' => "Category {$suffix}", 'slug' => "category-{$suffix}"]);
        $merchant = Merchant::create([
            'user_id' => $user->id, 'category_id' => $category->id, 'name' => "Merchant {$suffix}",
            'phone' => '021'.substr(md5($suffix), 0, 8), 'address' => 'Address', 'is_open' => true, 'is_active' => $active,
        ]);

        return [$user, $merchant];
    }

    private function order(
        string $type,
        string $status,
        User $user,
        ?Merchant $merchant = null,
        string $paymentStatus = 'pending',
        ?string $notes = null,
    ): Order {
        $order = Order::create([
            'order_number' => 'ADM-'.uniqid(), 'user_id' => $user->id, 'merchant_id' => $merchant?->id,
            'type' => $type, 'pickup_address' => 'Pickup', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8,
            'destination_address' => 'Destination', 'destination_latitude' => -6.3, 'destination_longitude' => 106.9,
            'subtotal' => 18000, 'delivery_fee' => 1000, 'service_fee' => 1000, 'total_price' => 20000,
            'payment_method' => 'cash', 'payment_status' => $paymentStatus, 'status' => $status, 'notes' => $notes,
        ]);
        $order->statusHistories()->create(['status' => $status, 'note' => 'Created', 'created_at' => now()]);

        return $order;
    }
}
