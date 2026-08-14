<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class PaymentRatingTest extends TestCase
{
    use BuildsAnterGoSchema;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
    }

    public function test_order_creation_creates_server_authoritative_cash_payment(): void
    {
        $customer = $this->user('customer');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/orders', $this->ridePayload())->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'status' => 'pending',
            'amount' => $order->total_price,
        ]);
    }

    public function test_assigned_driver_can_settle_completed_cash_idempotently(): void
    {
        [$driverUser, $driver] = $this->driver();
        $order = $this->completedOrder($driver);
        Sanctum::actingAs($driverUser);

        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle", ['amount' => 1])
            ->assertOk()->assertJsonPath('order.payment_status', 'paid');
        $paidAt = $order->payment()->firstOrFail()->paid_at;
        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle")->assertOk();

        $this->assertSame(1, $order->payment()->count());
        $this->assertEquals($order->total_price, $order->payment()->firstOrFail()->amount);
        $this->assertEquals($paidAt, $order->payment()->firstOrFail()->paid_at);
    }

    public function test_wrong_driver_customer_and_incomplete_order_cannot_settle_cash(): void
    {
        [$assignedUser, $assignedDriver] = $this->driver();
        [$otherUser] = $this->driver();
        $order = $this->completedOrder($assignedDriver);

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle")->assertNotFound();

        Sanctum::actingAs($order->user);
        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle")->assertForbidden();

        $order->update(['status' => Order::STATUS_IN_PROGRESS]);
        Sanctum::actingAs($assignedUser);
        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle")->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_customer_can_rate_assigned_driver_on_completed_ride_and_aggregate_updates(): void
    {
        [, $driver] = $this->driver();
        $order = $this->completedOrder($driver);
        $this->markPaid($order);
        Sanctum::actingAs($order->user);

        $this->postJson("/api/orders/{$order->id}/rating", [
            'target' => 'driver', 'rating' => 5, 'comment' => 'Mantap',
        ])->assertCreated()->assertJsonPath('rating.driver_id', $driver->id);

        $this->assertSame('5.00', $driver->fresh()->rating);
        $this->postJson("/api/orders/{$order->id}/rating", [
            'target' => 'driver', 'rating' => 4,
        ])->assertUnprocessable();
    }

    public function test_rating_validates_owner_completion_range_and_target_relation(): void
    {
        [, $driver] = $this->driver();
        $order = $this->completedOrder($driver);
        Sanctum::actingAs($this->user('customer'));
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 5])->assertNotFound();

        Sanctum::actingAs($order->user);
        $order->update(['status' => Order::STATUS_IN_PROGRESS]);
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 5])->assertUnprocessable();
        $order->update(['status' => Order::STATUS_COMPLETED]);
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 5])->assertUnprocessable();
        $this->markPaid($order);
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 0])->assertUnprocessable();
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 6])->assertUnprocessable();
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'merchant', 'rating' => 5])->assertUnprocessable();
    }

    public function test_food_can_rate_one_related_merchant_but_schema_prevents_second_target(): void
    {
        [, $driver] = $this->driver();
        [, $merchant] = $this->merchant();
        $order = $this->completedOrder($driver, Order::TYPE_FOOD, $merchant);
        $this->markPaid($order);
        Sanctum::actingAs($order->user);

        $this->postJson("/api/orders/{$order->id}/rating", [
            'target' => 'merchant', 'rating' => 4,
        ])->assertCreated()->assertJsonPath('rating.merchant_id', $merchant->id);
        $this->postJson("/api/orders/{$order->id}/rating", [
            'target' => 'driver', 'rating' => 5,
        ])->assertUnprocessable();
    }

    private function markPaid(Order $order): void
    {
        $order->update(['payment_status' => 'paid']);
        $order->payment()->update(['status' => 'paid', 'paid_at' => now()]);
    }
    private function completedOrder(Driver $driver, string $type = Order::TYPE_RIDE, ?Merchant $merchant = null): Order
    {
        $order = Order::create([
            'order_number' => 'TEST-'.$this->faker->unique()->numerify('######'),
            'user_id' => $this->user('customer')->id,
            'driver_id' => $driver->id,
            'merchant_id' => $merchant?->id,
            'type' => $type,
            'pickup_address' => 'Pickup', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8,
            'destination_address' => 'Tujuan', 'destination_latitude' => -6.21, 'destination_longitude' => 106.81,
            'subtotal' => 20000, 'delivery_fee' => 5000, 'service_fee' => 1000,
            'total_price' => 26000, 'payment_method' => 'cash', 'payment_status' => 'pending',
            'status' => Order::STATUS_COMPLETED, 'completed_at' => now(),
        ]);
        $order->payment()->create(['method' => 'cash', 'status' => 'pending', 'amount' => $order->total_price]);
        return $order;
    }

    private function user(string $role): User
    {
        $suffix = $this->faker->unique()->numerify('########');
        $user = User::create(['name' => ucfirst($role), 'email' => "$role-$suffix@example.com", 'phone' => "08$suffix", 'password' => Hash::make('password123'), 'is_active' => true]);
        $user->roles()->create(['role' => $role]);
        return $user;
    }

    private function driver(): array
    {
        $user = $this->user('driver');
        $driver = Driver::create(['user_id' => $user->id, 'nik' => 'NIK'.$user->id, 'license_number' => 'SIM'.$user->id, 'status' => 'approved']);
        return [$user, $driver];
    }

    private function merchant(): array
    {
        $user = $this->user('merchant');
        $category = MerchantCategory::create(['name' => 'Food '.$user->id, 'slug' => 'food-'.$user->id]);
        $merchant = Merchant::create(['user_id' => $user->id, 'category_id' => $category->id, 'name' => 'Merchant', 'phone' => '021'.$user->id, 'address' => 'Jl Merchant', 'latitude' => -6.2, 'longitude' => 106.8, 'is_open' => true, 'is_active' => true]);
        return [$user, $merchant];
    }

    private function ridePayload(): array
    {
        return ['pickup_address' => 'Pickup', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8, 'destination_address' => 'Tujuan', 'destination_latitude' => -6.21, 'destination_longitude' => 106.81];
    }
}
