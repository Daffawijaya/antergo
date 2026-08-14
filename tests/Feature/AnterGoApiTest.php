<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class AnterGoApiTest extends TestCase
{
    use BuildsAnterGoSchema;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
    }

    public function test_register_login_me_and_logout_flow(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Customer One',
            'email' => 'customer@example.com',
            'phone' => '081111111111',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $register->assertCreated()
            ->assertJsonPath('user.roles.0', 'customer')
            ->assertJsonStructure(['token']);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);
        $login->assertOk()->assertJsonStructure(['token']);

        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'customer@example.com');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) str($token)->before('|')->toString(),
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_customer_cannot_use_driver_endpoints(): void
    {
        Sanctum::actingAs($this->user('customer'));

        $this->postJson('/api/driver/online')->assertForbidden();
        $this->postJson('/api/driver/location', ['latitude' => -6.2, 'longitude' => 106.8])
            ->assertForbidden();
    }

    public function test_driver_can_go_online_and_offline(): void
    {
        [$user, $driver] = $this->driver();
        Sanctum::actingAs($user);

        $this->postJson('/api/driver/online')->assertOk()->assertJsonPath('driver.is_online', true);
        $this->assertTrue($driver->fresh()->is_online);

        $this->postJson('/api/driver/offline')->assertOk()->assertJsonPath('driver.is_online', false);
        $this->assertFalse($driver->fresh()->is_online);
    }

    public function test_online_driver_can_update_location(): void
    {
        [$user, $driver] = $this->driver(isOnline: true);
        Sanctum::actingAs($user);

        $this->postJson('/api/driver/location', [
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'heading' => 180,
            'speed' => 25.5,
        ])->assertOk()->assertJsonPath('location.driver_id', $driver->id);

        $this->assertDatabaseHas('driver_locations', [
            'driver_id' => $driver->id,
            'heading' => 180,
        ]);
    }

    public function test_customer_can_create_ride_order_with_server_calculated_fare(): void
    {
        $customer = $this->user('customer');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/orders', [
            ...$this->ridePayload(),
            'total_price' => 1,
            'subtotal' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.type', Order::TYPE_RIDE)
            ->assertJsonPath('order.status', Order::STATUS_SEARCHING_DRIVER)
            ->assertJsonPath('order.total_price', '10000.00')
            ->assertJsonPath('fare.total', 10000);
        $this->assertDatabaseHas('orders', ['user_id' => $customer->id, 'total_price' => 10000]);
    }

    public function test_driver_sees_nearby_available_ride_and_can_accept_it(): void
    {
        $order = $this->createRide();
        [$driverUser, $driver] = $this->driver(isOnline: true, withLocation: true);
        Sanctum::actingAs($driverUser);

        $this->getJson('/api/driver/orders/available')
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->id);

        $this->postJson("/api/driver/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('order.driver_id', $driver->id)
            ->assertJsonPath('order.status', Order::STATUS_DRIVER_ASSIGNED);
    }

    public function test_driver_can_restore_active_ride_and_view_ride_history(): void
    {
        $order = $this->createRide();
        [$driverUser] = $this->driver(isOnline: true);
        Sanctum::actingAs($driverUser);

        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        $this->getJson('/api/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.status', Order::STATUS_DRIVER_ASSIGNED);

        $this->getJson('/api/driver/orders/history')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        foreach ([Order::STATUS_DRIVER_ARRIVED, Order::STATUS_IN_PROGRESS, Order::STATUS_COMPLETED] as $status) {
            $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => $status])->assertOk();
        }

        $this->getJson('/api/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('order', null);

        $this->getJson('/api/driver/orders/history')
            ->assertOk()
            ->assertJsonPath('data.0.status', Order::STATUS_COMPLETED);
    }
    public function test_ride_lifecycle_reaches_completed(): void
    {
        $order = $this->createRide();
        [$driverUser, $driver] = $this->driver(isOnline: true);
        Sanctum::actingAs($driverUser);

        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();
        foreach ([Order::STATUS_DRIVER_ARRIVED, Order::STATUS_IN_PROGRESS, Order::STATUS_COMPLETED] as $status) {
            $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('order.status', $status);
        }

        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertSame(1, $driver->fresh()->total_completed_orders);
    }

    public function test_merchant_can_create_product_with_schema_fields(): void
    {
        [$merchantUser, $merchant] = $this->merchant();
        Sanctum::actingAs($merchantUser);

        $this->postJson('/api/merchant/products', [
            'name' => 'Nasi Goreng',
            'description' => 'Pedas',
            'price' => 25000,
            'stock' => 10,
            'image' => 'https://example.com/nasi.jpg',
        ])->assertCreated()
            ->assertJsonPath('product.merchant_id', $merchant->id)
            ->assertJsonPath('product.stock', 10);
    }

    public function test_merchant_can_only_update_or_delete_own_product(): void
    {
        [$ownerUser, $owner] = $this->merchant('owner');
        [, $other] = $this->merchant('other');
        $ownProduct = $this->product($owner);
        $otherProduct = $this->product($other);
        Sanctum::actingAs($ownerUser);

        $this->putJson("/api/merchant/products/{$ownProduct->id}", ['price' => 30000])
            ->assertOk()->assertJsonPath('product.price', '30000.00');
        $this->putJson("/api/merchant/products/{$otherProduct->id}", ['price' => 1])->assertNotFound();
        $this->deleteJson("/api/merchant/products/{$otherProduct->id}")->assertNotFound();
        $this->deleteJson("/api/merchant/products/{$ownProduct->id}")->assertOk();
        $this->assertFalse($ownProduct->fresh()->is_available);
    }

    public function test_food_order_snapshots_items_calculates_total_and_reduces_stock(): void
    {
        [, $merchant] = $this->merchant();
        $product = $this->product($merchant, stock: 10, price: 20000);
        $customer = $this->user('customer');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/food/orders', [
            ...$this->foodPayload($merchant, $product, 2),
            'total_price' => 1,
            'subtotal' => 1,
            'delivery_fee' => 0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.type', Order::TYPE_FOOD)
            ->assertJsonPath('order.status', Order::STATUS_PENDING)
            ->assertJsonPath('order.items.0.product_name', $product->name)
            ->assertJsonPath('order.items.0.quantity', 2);
        $order = Order::firstOrFail();
        $this->assertSame('40000.00', $order->subtotal);
        $this->assertSame('49000.00', $order->total_price);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_second_driver_cannot_take_an_already_assigned_order(): void
    {
        $order = $this->createRide();
        [$firstUser, $firstDriver] = $this->driver(isOnline: true);
        [$secondUser] = $this->driver(isOnline: true);

        Sanctum::actingAs($firstUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($secondUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertUnprocessable();
        $this->assertSame($firstDriver->id, $order->fresh()->driver_id);
    }

    public function test_driver_cannot_update_another_drivers_order(): void
    {
        $order = $this->createRide();
        [$assignedUser] = $this->driver(isOnline: true);
        [$otherUser] = $this->driver(isOnline: true);

        Sanctum::actingAs($assignedUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => Order::STATUS_DRIVER_ARRIVED,
        ])->assertForbidden();
        $this->assertSame(Order::STATUS_DRIVER_ASSIGNED, $order->fresh()->status);
    }

    public function test_customer_cannot_view_another_customers_order_but_admin_can(): void
    {
        $order = $this->createRide();

        Sanctum::actingAs($this->user('customer'));
        $this->getJson("/api/orders/{$order->id}")->assertNotFound();

        Sanctum::actingAs($this->user('admin'));
        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id);
    }

    public function test_merchant_cannot_manage_another_merchants_food_order(): void
    {
        [, $ownerMerchant] = $this->merchant('order-owner');
        $product = $this->product($ownerMerchant);
        $order = $this->createFoodOrder($ownerMerchant, $product);
        [$otherMerchantUser] = $this->merchant('order-other');
        Sanctum::actingAs($otherMerchantUser);

        $this->postJson("/api/merchant/orders/{$order->id}/confirm")->assertNotFound();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_food_order_rejects_insufficient_stock_without_partial_changes(): void
    {
        [, $merchant] = $this->merchant();
        $product = $this->product($merchant, stock: 1);
        Sanctum::actingAs($this->user('customer'));

        $this->postJson('/api/food/orders', $this->foodPayload($merchant, $product, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_merchant_order_lifecycle_then_driver_delivery(): void
    {
        [$merchantUser, $merchant] = $this->merchant();
        $product = $this->product($merchant);
        $order = $this->createFoodOrder($merchant, $product);
        Sanctum::actingAs($merchantUser);

        $this->postJson("/api/merchant/orders/{$order->id}/confirm")
            ->assertOk()->assertJsonPath('order.status', Order::STATUS_MERCHANT_CONFIRMED);
        $this->postJson("/api/merchant/orders/{$order->id}/status", ['status' => Order::STATUS_PREPARING])
            ->assertOk()->assertJsonPath('order.status', Order::STATUS_PREPARING);
        $this->postJson("/api/merchant/orders/{$order->id}/status", ['status' => Order::STATUS_READY_FOR_PICKUP])
            ->assertOk()->assertJsonPath('order.status', Order::STATUS_READY_FOR_PICKUP);

        [$driverUser] = $this->driver(isOnline: true);
        Sanctum::actingAs($driverUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();
        foreach ([Order::STATUS_PICKED_UP, Order::STATUS_DELIVERING, Order::STATUS_COMPLETED] as $status) {
            $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => $status])
                ->assertOk()->assertJsonPath('order.status', $status);
        }
    }

    public function test_driver_food_delivery_is_available_active_and_historic(): void
    {
        [$merchantUser, $merchant] = $this->merchant();
        $product = $this->product($merchant);
        $order = $this->readyFoodOrder($merchantUser, $merchant, $product);
        [$driverUser, $driver] = $this->driver(isOnline: true, withLocation: true);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/driver/orders/available')
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonPath('orders.0.type', Order::TYPE_FOOD)
            ->assertJsonPath('orders.0.merchant.id', $merchant->id)
            ->assertJsonPath('orders.0.items.0.product_id', $product->id);

        $this->postJson("/api/driver/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_DRIVER_ASSIGNED);

        $this->getJson('/api/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.type', Order::TYPE_FOOD);

        $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => Order::STATUS_DRIVER_ARRIVED,
        ])->assertUnprocessable();

        [$otherDriverUser] = $this->driver(isOnline: true);
        Sanctum::actingAs($otherDriverUser);
        $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => Order::STATUS_PICKED_UP,
        ])->assertForbidden();

        Sanctum::actingAs($driverUser);
        foreach ([Order::STATUS_PICKED_UP, Order::STATUS_DELIVERING, Order::STATUS_COMPLETED] as $status) {
            $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('order.status', $status);
        }

        $this->getJson('/api/driver/orders/active')->assertOk()->assertJsonPath('order', null);
        $this->getJson('/api/driver/orders/history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.type', Order::TYPE_FOOD);
        $this->assertSame(1, $driver->fresh()->total_completed_orders);
    }

    public function test_second_driver_cannot_take_an_already_assigned_food_order(): void
    {
        [$merchantUser, $merchant] = $this->merchant();
        $order = $this->readyFoodOrder($merchantUser, $merchant, $this->product($merchant));
        [$firstUser, $firstDriver] = $this->driver(isOnline: true);
        [$secondUser] = $this->driver(isOnline: true);

        Sanctum::actingAs($firstUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($secondUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertUnprocessable();
        $this->assertSame($firstDriver->id, $order->fresh()->driver_id);
    }
    public function test_phone_remains_unique(): void
    {
        $this->user('customer', '12345678');

        $this->postJson('/api/auth/register', [
            'name' => 'Duplicate Phone',
            'email' => 'different@example.com',
            'phone' => '0812345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_user_can_have_customer_and_driver_roles_without_duplicates(): void
    {
        $user = $this->user('customer');
        $user->roles()->create(['role' => UserRole::DRIVER]);

        $this->assertTrue($user->hasAnyRole(['customer', 'driver']));
        $this->assertSame(['customer', 'driver'], $user->roleNames());
        $this->expectException(QueryException::class);
        $user->roles()->create(['role' => UserRole::DRIVER]);
    }

    public function test_customer_with_approved_driver_profile_can_use_driver_endpoint(): void
    {
        $user = $this->user('customer');
        $user->roles()->create(['role' => UserRole::DRIVER]);
        Driver::create([
            'user_id' => $user->id,
            'nik' => 'NIK-MULTI',
            'license_number' => 'SIM-MULTI',
            'status' => 'approved',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/driver/online')->assertOk();
    }

    public function test_customer_can_create_merchant_profile_and_gain_merchant_role(): void
    {
        $user = $this->user('customer');
        $category = MerchantCategory::create(['name' => 'Multi', 'slug' => 'multi']);
        Sanctum::actingAs($user);

        $this->postJson('/api/merchant', [
            'category_id' => $category->id,
            'name' => 'Multi Merchant',
            'phone' => '021000000',
            'address' => 'Jl. Multi',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ])->assertCreated();

        $this->assertTrue($user->hasRole(UserRole::MERCHANT));
        $this->getJson('/api/merchant/me')->assertOk();
    }

    public function test_unauthenticated_and_wrong_role_access_is_rejected(): void
    {
        $this->postJson('/api/orders', $this->ridePayload())->assertUnauthorized();

        Sanctum::actingAs($this->user('merchant'));
        $this->postJson('/api/orders', $this->ridePayload())->assertForbidden();
    }

    public function test_invalid_order_status_transition_is_rejected(): void
    {
        $order = $this->createRide();
        [$driverUser] = $this->driver(isOnline: true);
        Sanctum::actingAs($driverUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => Order::STATUS_COMPLETED,
        ])->assertUnprocessable();
        $this->assertSame(Order::STATUS_DRIVER_ASSIGNED, $order->fresh()->status);
    }

    private function user(string $role, ?string $suffix = null): User
    {
        $suffix ??= $this->faker->unique()->numerify('########');

        $user = User::create([
            'name' => ucfirst($role).' '.$suffix,
            'email' => "{$role}-{$suffix}@example.com",
            'phone' => '08'.$suffix,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function driver(bool $isOnline = false, bool $withLocation = false): array
    {
        $suffix = $this->faker->unique()->numerify('########');
        $user = $this->user('driver', $suffix);
        $driver = Driver::create([
            'user_id' => $user->id,
            'nik' => 'NIK'.$suffix,
            'license_number' => 'SIM'.$suffix,
            'status' => 'approved',
            'is_online' => $isOnline,
        ]);

        if ($withLocation) {
            $driver->location()->create([
                'latitude' => -6.2000000,
                'longitude' => 106.8166667,
                'updated_at' => now(),
            ]);
        }

        return [$user, $driver];
    }

    private function merchant(?string $suffix = null): array
    {
        $suffix ??= $this->faker->unique()->numerify('########');
        $user = $this->user('merchant', $suffix);
        $category = MerchantCategory::create(['name' => 'Food '.$suffix, 'slug' => 'food-'.$suffix]);
        $merchant = Merchant::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name' => 'Merchant '.$suffix,
            'phone' => '021'.$suffix,
            'address' => 'Jl. Merchant',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'is_open' => true,
            'is_active' => true,
        ]);

        return [$user, $merchant];
    }

    private function product(Merchant $merchant, int $stock = 10, int $price = 20000): Product
    {
        return Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'Product '.$this->faker->unique()->numerify('####'),
            'price' => $price,
            'stock' => $stock,
            'is_available' => true,
        ]);
    }

    private function ridePayload(): array
    {
        return [
            'pickup_address' => 'Jl. Pickup',
            'pickup_latitude' => -6.2000000,
            'pickup_longitude' => 106.8166667,
            'destination_address' => 'Jl. Destination',
            'destination_latitude' => -6.2000000,
            'destination_longitude' => 106.8166667,
        ];
    }

    private function createRide(): Order
    {
        Sanctum::actingAs($this->user('customer'));
        $id = $this->postJson('/api/orders', $this->ridePayload())->assertCreated()->json('order.id');

        return Order::findOrFail($id);
    }

    private function readyFoodOrder(User $merchantUser, Merchant $merchant, Product $product): Order
    {
        $order = $this->createFoodOrder($merchant, $product);
        Sanctum::actingAs($merchantUser);
        $this->postJson("/api/merchant/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/merchant/orders/{$order->id}/status", [
            'status' => Order::STATUS_PREPARING,
        ])->assertOk();
        $this->postJson("/api/merchant/orders/{$order->id}/status", [
            'status' => Order::STATUS_READY_FOR_PICKUP,
        ])->assertOk();

        return $order->fresh();
    }
    private function foodPayload(Merchant $merchant, Product $product, int $quantity = 1): array
    {
        return [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'destination_address' => 'Jl. Customer',
            'destination_latitude' => -6.2000000,
            'destination_longitude' => 106.8166667,
            'payment_method' => 'cash',
        ];
    }

    private function createFoodOrder(Merchant $merchant, Product $product): Order
    {
        Sanctum::actingAs($this->user('customer'));
        $id = $this->postJson('/api/food/orders', $this->foodPayload($merchant, $product))
            ->assertCreated()->json('order.id');

        return Order::findOrFail($id);
    }
}
