<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
    }

    public function test_only_assigned_customer_and_driver_can_chat(): void
    {
        $customer = $this->user('customer');
        $driverUser = $this->user('driver');
        $driver = Driver::create(['user_id' => $driverUser->id, 'nik' => 'NIK1', 'license_number' => 'SIM1', 'status' => 'approved']);
        $order = Order::create(['order_number' => 'AG-CHAT', 'user_id' => $customer->id, 'driver_id' => $driver->id, 'type' => 'ride', 'pickup_address' => 'A', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8, 'destination_address' => 'B', 'destination_latitude' => -6.3, 'destination_longitude' => 106.9, 'subtotal' => 10000, 'total_price' => 10000, 'status' => 'driver_assigned']);

        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$order->id}/messages", ['body' => 'Saya di depan'])->assertCreated()->assertJsonPath('message.body', 'Saya di depan');

        Sanctum::actingAs($driverUser);
        $this->getJson("/api/orders/{$order->id}/messages")->assertOk()->assertJsonPath('messages.0.body', 'Saya di depan');
        $this->postJson("/api/orders/{$order->id}/messages", ['body' => 'Baik'])->assertCreated();

        Sanctum::actingAs($this->user('customer'));
        $this->getJson("/api/orders/{$order->id}/messages")->assertNotFound();
    }

    public function test_chat_requires_assigned_driver_and_valid_body(): void
    {
        $customer = $this->user('customer');
        $order = Order::create(['order_number' => 'AG-NO-DRIVER', 'user_id' => $customer->id, 'type' => 'ride', 'pickup_address' => 'A', 'pickup_latitude' => -6.2, 'pickup_longitude' => 106.8, 'destination_address' => 'B', 'destination_latitude' => -6.3, 'destination_longitude' => 106.9, 'subtotal' => 10000, 'total_price' => 10000, 'status' => 'searching_driver']);
        Sanctum::actingAs($customer);
        $this->postJson("/api/orders/{$order->id}/messages", ['body' => ''])->assertNotFound();
    }

    private function user(string $role): User
    {
        static $number = 0;
        $number++;
        $user = User::create(['name' => ucfirst($role), 'email' => "chat{$number}@example.com", 'phone' => '08123'.$number, 'password' => Hash::make('password'), 'is_active' => true]);
        $user->roles()->create(['role' => $role]);

        return $user;
    }
}
