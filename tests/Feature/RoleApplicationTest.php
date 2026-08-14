<?php

namespace Tests\Feature;

use App\Models\MerchantCategory;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class RoleApplicationTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
    }

    public function test_customer_can_submit_driver_application_without_receiving_driver_role(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/driver/application', [
            'nik' => '1234567890123456',
            'license_number' => 'SIM-12345',
            'vehicle_type' => 'motorcycle',
            'brand' => 'Honda',
            'model' => 'Vario',
            'plate_number' => 'KT 1234 AG',
            'color' => 'Hitam',
        ])->assertCreated()
            ->assertJsonPath('driver.status', 'pending')
            ->assertJsonPath('driver.vehicle.type', 'motorcycle');

        $this->assertFalse($user->hasRole(UserRole::DRIVER));
        $this->getJson('/api/driver/application')->assertOk()->assertJsonPath('driver.id', $response->json('driver.id'));
        $this->postJson('/api/driver/application', [])->assertUnprocessable();
    }

    public function test_merchant_categories_are_available_for_registration(): void
    {
        MerchantCategory::create(['name' => 'Makanan & Minuman', 'slug' => 'food']);

        $this->getJson('/api/merchant-categories')
            ->assertOk()
            ->assertJsonPath('categories.0.slug', 'food');
    }

    private function customer(): User
    {
        $user = User::create([
            'name' => 'Customer',
            'email' => 'application@example.com',
            'phone' => '081234567890',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->roles()->create(['role' => UserRole::CUSTOMER]);

        return $user;
    }
}
