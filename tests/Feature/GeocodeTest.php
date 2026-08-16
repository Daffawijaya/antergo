<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class GeocodeTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
        config(['services.geoapify.key' => null]);
    }

    public function test_own_merchants_are_searched_first(): void
    {
        $this->createMerchant('Mimi Fried Chicken', 'Jalan Pahlawan No. 1, Samarinda', -0.502, 117.153);

        // limit=1 so the own-merchant hit fully satisfies the request and no
        // external geocoder is consulted.
        $response = $this->getJson('/api/geocode?q=mimi&lat=-0.502&lon=117.153&limit=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mimi Fried Chicken')
            ->assertJsonPath('data.0.source', 'merchant')
            ->assertJsonPath('data.0.distance', 0)
            ->assertJsonPath('data.0.address', 'Jalan Pahlawan No. 1, Samarinda')
            ->assertJsonPath('data.0.coordinate.latitude', -0.502)
            ->assertJsonPath('data.0.coordinate.longitude', 117.153);

        Http::assertNothingSent();
    }

    public function test_geoapify_fills_results_when_no_merchant_matches(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    ['properties' => [
                        'lat' => -0.502,
                        'lon' => 117.153,
                        'name' => 'Mimi Fried Chicken',
                        'formatted' => 'Mimi Fried Chicken, Jalan Pahlawan No. 1, Samarinda, Kalimantan Timur, Indonesia',
                    ]],
                    ['properties' => [
                        'lat' => -6.9175,
                        'lon' => 107.6191,
                        'name' => 'Mimi',
                        'formatted' => 'Mimi, Jalan Soekarno Hatta, Bandung, Jawa Barat, Indonesia',
                    ]],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode?q=mimi&lat=-0.502&lon=117.153');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'geoapify')
            ->assertJsonPath('data.0.name', 'Mimi Fried Chicken')
            ->assertJsonPath('data.0.distance', 0)
            ->assertJsonPath('data.0.address', 'Jalan Pahlawan No. 1, Samarinda, Kalimantan Timur');

        Http::assertSent(function ($request) {
            $url = rawurldecode($request->url());

            return str_contains($url, 'api.geoapify.com')
                && str_contains($url, 'filter=countrycode:id')
                && str_contains($url, 'bias=proximity:117.153,-0.502');
        });
    }

    public function test_falls_back_to_nominatim_when_geoapify_key_missing(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '-0.5257936',
                    'lon' => '117.1158248',
                    'name' => 'bigmall samarinda',
                    'display_name' => 'bigmall samarinda, Jalan Untung Suropati, Karang Asam Ulu, Sungai Kunjang, Samarinda, Kalimantan Timur, Kalimantan, 75132, Indonesia',
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->getJson('/api/geocode?q=bigmall');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'nominatim')
            ->assertJsonPath('data.0.name', 'bigmall samarinda')
            ->assertJsonPath('data.0.address', 'Jalan Untung Suropati, Karang Asam Ulu, Sungai Kunjang, Samarinda, Kalimantan Timur, Kalimantan, 75132');
    }

    public function test_search_requires_query(): void
    {
        $this->getJson('/api/geocode')->assertStatus(422);
    }

    public function test_merchants_without_coordinates_are_skipped(): void
    {
        $this->createMerchant('Mimi Fried Chicken', 'Jalan Pahlawan No. 1, Samarinda', null, null);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->getJson('/api/geocode?q=mimi')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    private function createMerchant(string $name, string $address, ?float $lat, ?float $lon): Merchant
    {
        $user = User::create([
            'name' => 'Merchant Owner',
            'email' => 'owner-'.uniqid().'@example.com',
            'phone' => '08'.random_int(100000000, 999999999),
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        return Merchant::create([
            'user_id' => $user->id,
            'name' => $name,
            'phone' => '02112345678',
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lon,
            'is_open' => true,
            'is_active' => true,
        ]);
    }
}
