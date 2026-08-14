<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class ServiceVariantTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
    }

    public function test_bike_and_car_store_vehicle_requirement_and_use_different_pricing(): void
    {
        Sanctum::actingAs($this->user('customer'));
        $bike = $this->postJson('/api/orders', $this->ridePayload('bike'))->assertCreated();
        $car = $this->postJson('/api/orders', $this->ridePayload('car'))->assertCreated();

        $bike->assertJsonPath('order.service_variant', 'bike')->assertJsonPath('order.vehicle_type', 'motorcycle');
        $car->assertJsonPath('order.service_variant', 'car')->assertJsonPath('order.vehicle_type', 'car');
        $this->assertGreaterThan($bike->json('order.total_price'), $car->json('order.total_price'));
    }

    public function test_driver_only_sees_orders_matching_vehicle(): void
    {
        Sanctum::actingAs($this->user('customer'));
        $bikeId = $this->postJson('/api/orders', $this->ridePayload('bike'))->assertCreated()->json('order.id');
        $carId = $this->postJson('/api/orders', $this->ridePayload('car'))->assertCreated()->json('order.id');
        [$carUser] = $this->driver('car');
        Sanctum::actingAs($carUser);
        $ids = collect($this->getJson('/api/driver/orders/available')->assertOk()->json('orders'))->pluck('id');
        $this->assertTrue($ids->contains($carId));
        $this->assertFalse($ids->contains($bikeId));
    }

    public function test_delivery_accepts_motorcycle_or_car_and_shopping_only_accepts_goods(): void
    {
        $customer = $this->user('customer');
        Sanctum::actingAs($customer);
        $this->postJson('/api/send/orders', $this->deliveryPayload('car'))->assertCreated()->assertJsonPath('order.vehicle_type', 'car')->assertJsonPath('order.service_variant', 'delivery');

        $merchantUser = $this->user('merchant');
        $merchant = Merchant::create(['user_id' => $merchantUser->id, 'name' => 'UMKM', 'phone' => '0800', 'address' => 'Toko', 'latitude' => -6.2, 'longitude' => 106.8, 'is_open' => true, 'is_active' => true]);
        $food = Product::create(['merchant_id' => $merchant->id, 'product_type' => 'food', 'name' => 'Nasi', 'price' => 10000, 'stock' => 5, 'is_available' => true]);
        $goods = Product::create(['merchant_id' => $merchant->id, 'product_type' => 'goods', 'name' => 'Tas', 'price' => 50000, 'stock' => 5, 'is_available' => true]);

        $this->postJson('/api/shopping/orders', $this->commercePayload($merchant->id, $food->id))->assertUnprocessable();
        $this->postJson('/api/shopping/orders', $this->commercePayload($merchant->id, $goods->id))->assertCreated()->assertJsonPath('order.service_variant', 'shopping');
    }

    private function ridePayload(string $service): array
    {
        return ['pickup_address' => 'A', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8, 'destination_address' => 'B', 'destination_latitude' => -6.25, 'destination_longitude' => 106.85, 'service_type' => $service];
    }

    private function deliveryPayload(string $vehicle): array
    {
        return $this->ridePayload('bike') + ['item_name' => 'Box', 'recipient_name' => 'Budi', 'recipient_phone' => '0812345678', 'vehicle_type' => $vehicle];
    }

    private function commercePayload(int $merchantId, int $productId): array
    {
        return ['merchant_id' => $merchantId, 'items' => [['product_id' => $productId, 'quantity' => 1]], 'destination_address' => 'Rumah', 'destination_latitude' => -6.25, 'destination_longitude' => 106.85, 'payment_method' => 'cash'];
    }

    private function user(string $role): User
    {
        static $id = 0;
        $id++;
        $user = User::create(['name' => ucfirst($role), 'email' => "variant{$id}@example.com", 'phone' => '089'.$id, 'password' => Hash::make('password'), 'is_active' => true]);
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function driver(string $type): array
    {
        $user = $this->user('driver');
        $driver = Driver::create(['user_id' => $user->id, 'nik' => 'NIK'.$user->id, 'license_number' => 'SIM'.$user->id, 'status' => 'approved', 'is_online' => true]);
        $driver->vehicle()->create(['type' => $type, 'brand' => 'Test', 'plate_number' => 'B'.$user->id]);
        $driver->location()->create(['latitude' => -6.2, 'longitude' => 106.8, 'updated_at' => now()]);

        return [$user, $driver];
    }
}
