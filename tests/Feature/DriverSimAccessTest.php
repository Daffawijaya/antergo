<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsanterGoSchema;
use Tests\TestCase;

class DriverSimAccessTest extends TestCase
{
    use BuildsanterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildanterGoSchema();
        config([
            'services.supabase_storage.url' => 'https://storage.test',
            'services.supabase_storage.service_key' => 'key',
        ]);
        Http::fake(['https://storage.test/*' => Http::response(['Key' => 'stored'], 200)]);
    }

    public function test_driver_cannot_go_online_when_active_vehicle_sim_is_expired(): void
    {
        $user = $this->driverWithExpiredSim('car', 'sim_a');
        Sanctum::actingAs($user);

        $this->post('/api/driver/online', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sim');
    }

    public function test_driver_can_go_online_when_active_vehicle_sim_is_valid(): void
    {
        $user = $this->driverWithValidSim('car', 'sim_a');
        Sanctum::actingAs($user);

        $this->post('/api/driver/online', [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.is_online', true);
    }

    public function test_driver_cannot_select_car_vehicle_with_expired_sim_a(): void
    {
        $user = $this->driverWithExpiredSim('car', 'sim_a');
        $car = Vehicle::create([
            'driver_id' => $user->driver->id,
            'type' => 'car',
            'brand' => 'Toyota',
            'model' => 'Avanza',
            'plate_number' => 'KT 3 CC',
            'color' => 'Putih',
            'image_path' => 'vehicles/1/1/x.webp',
        ]);
        Sanctum::actingAs($user);

        $this->post("/api/driver/vehicles/{$car->id}/active", [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sim');
    }

    public function test_driver_cannot_add_matching_vehicle_when_sim_is_expired(): void
    {
        $user = $this->driverWithExpiredSim('motorcycle', 'sim_c');
        Sanctum::actingAs($user);

        $this->post('/api/driver/vehicles', [
            'type' => 'motorcycle',
            'brand' => 'Yamaha',
            'model' => 'NMAX',
            'plate_number' => 'KT 4 DD',
            'color' => 'Hitam',
            'image' => $this->image('vehicle.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sim');
    }

    private function driverWithExpiredSim(string $vehicleType, string $simType): User
    {
        $user = $this->driver();
        $vehicle = Vehicle::create([
            'driver_id' => $user->driver->id,
            'type' => $vehicleType,
            'brand' => 'Honda',
            'model' => 'Vario',
            'plate_number' => 'KT 5 EE',
            'color' => 'Merah',
            'image_path' => 'vehicles/1/1/x.webp',
        ]);
        $user->driver->update(['active_vehicle_id' => $vehicle->id]);
        $user->driver->documents()->create([
            'type' => $simType,
            'file_path' => '1/sim/x.webp',
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        return $user;
    }

    private function driverWithValidSim(string $vehicleType, string $simType): User
    {
        $user = $this->driver();
        $vehicle = Vehicle::create([
            'driver_id' => $user->driver->id,
            'type' => $vehicleType,
            'brand' => 'Honda',
            'model' => 'Brio',
            'plate_number' => 'KT 6 FF',
            'color' => 'Abu',
            'image_path' => 'vehicles/1/1/x.webp',
        ]);
        $user->driver->update(['active_vehicle_id' => $vehicle->id]);
        $user->driver->documents()->create([
            'type' => $simType,
            'file_path' => '1/sim/x.webp',
            'expires_at' => now()->addYear()->toDateString(),
        ]);

        return $user;
    }

    private function driver(): User
    {
        $user = User::create([
            'name' => 'Driver',
            'email' => 'simaccess@example.com',
            'phone' => '081234567892',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->roles()->create(['role' => UserRole::DRIVER]);
        Driver::create([
            'user_id' => $user->id,
            'nik' => '1234567890123456',
            'license_number' => 'LIC-'.str()->random(8),
            'status' => 'approved',
        ]);

        return $user;
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
