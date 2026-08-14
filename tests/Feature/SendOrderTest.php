<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Order;
use App\Models\PushDeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class SendOrderTest extends TestCase
{
    use BuildsAnterGoSchema;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    }

    public function test_customer_creates_send_with_server_pricing_and_structured_details(): void
    {
        $customer = $this->user('customer');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/send/orders', $this->payload() + [
            'distance' => 999, 'total_price' => 1, 'subtotal' => 1, 'service_fee' => 1,
        ])->assertCreated()
            ->assertJsonPath('order.type', Order::TYPE_SEND)
            ->assertJsonPath('order.status', Order::STATUS_SEARCHING_DRIVER)
            ->assertJsonPath('order.payment.method', 'cash')
            ->assertJsonPath('order.send_details.item_name', 'Dokumen penting')
            ->assertJsonPath('order.notes', 'Jangan dilipat');

        $order = Order::findOrFail($response->json('order.id'));
        $this->assertGreaterThan(1, (float) $order->total_price);
        $this->assertEquals($order->total_price, $order->payment->amount);
        $this->assertNotEquals(999, (float) $order->distance);
    }

    public function test_send_is_available_accept_is_locked_and_active_supports_send(): void
    {
        $order = $this->createSend();
        [$driverUser, $driver] = $this->driver(withLocation: true);
        [$otherUser] = $this->driver(withLocation: true);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/driver/orders/available')->assertOk()
            ->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonPath('orders.0.type', Order::TYPE_SEND);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_DRIVER_ASSIGNED);
        $this->getJson('/api/driver/orders/active')->assertOk()
            ->assertJsonPath('order.type', Order::TYPE_SEND);

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertUnprocessable();
        $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => Order::STATUS_DRIVER_ARRIVED])->assertForbidden();
        $this->assertSame($driver->id, $order->fresh()->driver_id);
    }

    public function test_send_lifecycle_rejects_invalid_transition_and_reaches_history(): void
    {
        $order = $this->createSend();
        [$driverUser] = $this->driver(withLocation: true);
        Sanctum::actingAs($driverUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();
        $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => Order::STATUS_PICKED_UP])->assertUnprocessable();

        foreach ([Order::STATUS_DRIVER_ARRIVED, Order::STATUS_PICKED_UP, Order::STATUS_DELIVERING, Order::STATUS_COMPLETED] as $status) {
            $this->postJson("/api/driver/orders/{$order->id}/status", ['status' => $status])
                ->assertOk()->assertJsonPath('order.status', $status);
        }

        $this->getJson('/api/driver/orders/active')->assertJsonPath('order', null);
        $this->getJson('/api/driver/orders/history')->assertJsonPath('data.0.type', Order::TYPE_SEND);
    }

    public function test_send_cancel_is_allowed_before_pickup_but_rejected_after_pickup(): void
    {
        $order = $this->createSend();
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel", ['cancelled_reason' => 'Berubah pikiran'])
            ->assertOk()->assertJsonPath('order.status', Order::STATUS_CANCELLED);

        $pickedUp = $this->assignedSend(Order::STATUS_PICKED_UP);
        Sanctum::actingAs($pickedUp->user);
        $this->postJson("/api/orders/{$pickedUp->id}/cancel")->assertUnprocessable();
    }

    public function test_completed_send_reuses_cash_settlement_and_driver_rating(): void
    {
        $order = $this->assignedSend(Order::STATUS_COMPLETED);
        $driverUser = $order->driver->user;
        Sanctum::actingAs($driverUser);
        $this->postJson("/api/driver/orders/{$order->id}/payments/cash/settle", ['amount' => 1])
            ->assertOk()->assertJsonPath('order.payment_status', 'paid');

        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/rating", ['target' => 'driver', 'rating' => 5, 'comment' => 'Aman'])
            ->assertCreated()->assertJsonPath('rating.driver_id', $order->driver_id);
    }

    public function test_send_push_payload_uses_send_deep_links_without_private_details(): void
    {
        $order = $this->createSend();
        PushDeviceToken::create(['user_id' => $order->user_id, 'token' => 'ExponentPushToken[send-customer]', 'platform' => 'android']);
        [$driverUser] = $this->driver(withLocation: true);
        PushDeviceToken::create(['user_id' => $driverUser->id, 'token' => 'ExponentPushToken[send-driver]', 'platform' => 'android']);
        Sanctum::actingAs($driverUser);
        $this->postJson("/api/driver/orders/{$order->id}/accept")->assertOk();

        Http::assertSent(function ($request) use ($order) {
            $payload = $request->data()[0] ?? [];
            return data_get($payload, 'data.route') === 'customer_send_detail'
                && data_get($payload, 'data.order_id') === $order->id
                && ! array_key_exists('send_details', data_get($payload, 'data', []));
        });

        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        Http::assertSent(fn ($request) => data_get($request->data()[0] ?? [], 'data.route') === 'driver_send_detail');
    }

    private function assignedSend(string $status): Order
    {
        $order = $this->createSend();
        [, $driver] = $this->driver();
        $order->update(['driver_id' => $driver->id, 'status' => $status, 'completed_at' => $status === Order::STATUS_COMPLETED ? now() : null]);
        return $order->fresh(['user', 'driver.user', 'payment']);
    }

    private function createSend(): Order
    {
        Sanctum::actingAs($this->user('customer'));
        $id = $this->postJson('/api/send/orders', $this->payload())->assertCreated()->json('order.id');
        return Order::with(['user', 'payment'])->findOrFail($id);
    }

    private function payload(): array
    {
        return [
            'pickup_address' => 'Jl. Pickup', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8,
            'destination_address' => 'Jl. Penerima', 'destination_latitude' => -6.25, 'destination_longitude' => 106.85,
            'item_name' => 'Dokumen penting', 'item_description' => 'Amplop cokelat',
            'recipient_name' => 'Budi', 'recipient_phone' => '081234567890',
            'notes' => 'Jangan dilipat', 'payment_method' => 'cash',
        ];
    }

    private function user(string $role): User
    {
        $suffix = $this->faker->unique()->numerify('########');
        $user = User::create(['name' => ucfirst($role), 'email' => "$role-$suffix@example.com", 'phone' => "08$suffix", 'password' => Hash::make('password123'), 'is_active' => true]);
        $user->roles()->create(['role' => $role]);
        return $user;
    }

    private function driver(bool $withLocation = false): array
    {
        $user = $this->user('driver');
        $driver = Driver::create(['user_id' => $user->id, 'nik' => 'NIK'.$user->id, 'license_number' => 'SIM'.$user->id, 'status' => 'approved', 'is_online' => true]);
        if ($withLocation) {
            $driver->location()->create(['latitude' => -6.2, 'longitude' => 106.8, 'updated_at' => now()]);
        }
        return [$user, $driver];
    }
}
