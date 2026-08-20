<?php

namespace Tests\Feature;

use App\Models\MerchantCategory;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsanterGoSchema;
use Tests\TestCase;

class RoleApplicationTest extends TestCase
{
    use BuildsanterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildanterGoSchema();
        config(['services.supabase_storage.url' => 'https://storage.test', 'services.supabase_storage.service_key' => 'key']);
        Http::fake(['https://storage.test/*' => Http::response(['Key' => 'stored'], 200)]);
    }

    public function test_customer_can_submit_driver_application_without_receiving_driver_role(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $response = $this->post('/api/driver/application', [
            'nik' => '1234567890123456',
            'avatar' => $this->image('avatar.png'),
            'ktp' => $this->image('ktp.png'),
            'sim_c' => $this->image('sim-c.png'),
            'vehicles' => [[
                'type' => 'motorcycle', 'brand' => 'Honda', 'model' => 'Vario',
                'plate_number' => 'KT 1234 AG', 'color' => 'Hitam',
            ]],
            'vehicle_images' => [$this->image('vehicle.png')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('driver.status', 'pending')
            ->assertJsonPath('driver.vehicle.type', 'motorcycle')
            ->assertJsonPath('driver.documents.0.uploaded', true);
        $this->assertFalse($user->hasRole(UserRole::DRIVER));
        $this->getJson('/api/driver/application')->assertOk()->assertJsonPath('driver.id', $response->json('driver.id'));
        $this->postJson('/api/driver/application', [])->assertUnprocessable();
    }

    public function test_driver_application_rejects_missing_media_and_vehicle(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        $this->postJson('/api/driver/application', ['nik' => '1234567890123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar', 'ktp', 'vehicles', 'vehicle_images']);
    }

    public function test_motor_requires_sim_c_and_driver_can_register_multiple_vehicles(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        $base = [
            'nik' => '1234567890123456', 'avatar' => $this->image('avatar.png'),
            'ktp' => $this->image('ktp.png'),
            'vehicles' => [['type' => 'motorcycle', 'brand' => 'Honda', 'model' => 'Beat', 'plate_number' => 'KT 1 AA', 'color' => 'Black']],
            'vehicle_images' => [$this->image('motor.png')],
        ];
        $this->post('/api/driver/application', $base, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('sim_c');

        $base['sim_c'] = $this->image('sim-c.png');
        $base['sim_a'] = $this->image('sim-a.png');
        $base['vehicles'][] = ['type' => 'car', 'brand' => 'Toyota', 'model' => 'Avanza', 'plate_number' => 'KT 2 BB', 'color' => 'White'];
        $base['vehicle_images'][] = $this->image('car.png');
        $this->post('/api/driver/application', $base, ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'driver.vehicles');
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseHas('driver_documents', ['type' => 'sim_a']);
        $this->assertDatabaseHas('driver_documents', ['type' => 'sim_c']);
    }

    public function test_merchant_categories_are_available_for_registration(): void
    {
        MerchantCategory::create(['name' => 'Makanan & Minuman', 'slug' => 'food']);

        $this->getJson('/api/merchant-categories')
            ->assertOk()
            ->assertJsonPath('categories.0.slug', 'food');
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
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
