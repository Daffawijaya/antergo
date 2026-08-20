<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsanterGoSchema;
use Tests\TestCase;

class GeocodeTest extends TestCase
{
    use BuildsanterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildanterGoSchema();
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
            // Nominatim must stay quiet so the test asserts only Geoapify hits.
            'nominatim.openstreetmap.org/*' => Http::response([], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->getJson('/api/geocode?q=mimi&lat=-0.502&lon=117.153');

        // The local match comes first; the far-away "Mimi" in Bandung
        // (~1,000 km) is beyond the radius but still a real query match, so the
        // country-wide fallback appends it after the local results.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'geoapify')
            ->assertJsonPath('data.0.name', 'Mimi Fried Chicken')
            ->assertJsonPath('data.0.distance', 0)
            ->assertJsonPath('data.0.address', 'Jalan Pahlawan No. 1, Samarinda, Kalimantan Timur')
            ->assertJsonPath('data.1.source', 'geoapify')
            ->assertJsonPath('data.1.name', 'Mimi')
            ->assertJsonPath('data.1.address', 'Jalan Soekarno Hatta, Bandung, Jawa Barat');

        Http::assertSent(function ($request) {
            $url = rawurldecode($request->url());

            return str_contains($url, 'api.geoapify.com')
                && str_contains($url, 'filter=circle:117.153,-0.502,20000')
                && str_contains($url, 'bias=proximity:117.153,-0.502');
        });
    }

    public function test_search_tops_up_with_nominatim_when_geoapify_is_thin(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'lat' => -0.502,
                        'lon' => 117.153,
                        'name' => 'Mimi Fried Chicken',
                        'formatted' => 'Mimi Fried Chicken, Jalan Pahlawan No. 1, Samarinda, Kalimantan Timur, Indonesia',
                    ],
                ]],
            ], 200),
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '-0.501',
                    'lon' => '117.152',
                    'name' => 'Mimi Ayam Geprek',
                    'display_name' => 'Mimi Ayam Geprek, Jalan Gatot Subroto, Samarinda, Kalimantan Timur, Indonesia',
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->getJson('/api/geocode?q=mimi&lat=-0.502&lon=117.153&limit=3');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'geoapify')
            ->assertJsonPath('data.0.name', 'Mimi Fried Chicken')
            ->assertJsonPath('data.1.source', 'nominatim')
            ->assertJsonPath('data.1.name', 'Mimi Ayam Geprek');
    }

    public function test_search_keeps_local_first_and_deduplicates_with_far_fallback(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'lat' => -0.3855,
                            'lon' => 117.1105,
                            'name' => 'SMA Negeri 2 Tenggarong Seberang',
                            'formatted' => 'SMA Negeri 2 Tenggarong Seberang, Jalan Samarinda - Muara Kaman, Kerta Buana, Kutai Kartanegara, Indonesia',
                        ],
                    ],
                    // Same place again from the same source — must be deduplicated.
                    [
                        'properties' => [
                            'lat' => -0.3855,
                            'lon' => 117.1105,
                            'name' => 'SMA Negeri 2 Tenggarong Seberang',
                            'formatted' => 'SMA Negeri 2 Tenggarong Seberang, Jalan Samarinda - Muara Kaman, Kerta Buana, Kutai Kartanegara, Indonesia',
                        ],
                    ],
                    // Far away (~1,000 km) — beyond the radius, so only the
                    // country-wide fallback brings it back, after the local hit.
                    [
                        'properties' => [
                            'lat' => -6.9175,
                            'lon' => 107.6191,
                            'name' => 'SMAN 2 Bandung',
                            'formatted' => 'SMAN 2 Bandung, Jalan Cihampelas, Bandung, Jawa Barat, Indonesia',
                        ],
                    ],
                ],
            ], 200),
            'nominatim.openstreetmap.org/*' => Http::response([], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->getJson('/api/geocode?q=sman&lat=-0.3813551&lon=117.1147299&limit=10');

        // The duplicate is dropped, the local hit ranks first, and the far match
        // surfaces through the country-wide fallback without repeating the local one.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'SMA Negeri 2 Tenggarong Seberang')
            ->assertJsonPath('data.0.source', 'geoapify')
            ->assertJsonPath('data.1.name', 'SMAN 2 Bandung')
            ->assertJsonPath('data.1.source', 'geoapify');
    }

    public function test_search_falls_back_country_wide_when_no_local_matches(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            $url = rawurldecode($request->url());

            // The radius-bounded pass finds nothing around the Samarinda reference.
            if (str_contains($url, 'filter=circle:117.154,-0.502,20000')) {
                return Http::response(['features' => []], 200);
            }

            // The country-wide fallback finds the airport in Balikpapan (~110 km).
            return Http::response([
                'features' => [[
                    'properties' => [
                        'lat' => -1.2683,
                        'lon' => 116.8943,
                        'name' => 'Bandara Sultan Aji Muhammad Sulaiman Sepinggan',
                        'formatted' => 'Bandara Sultan Aji Muhammad Sulaiman Sepinggan, Jalan Marsma R. Iswahyudi, Sepinggan, Balikpapan, Kalimantan Timur, Indonesia',
                    ],
                ]],
            ], 200);
        });

        $response = $this->getJson('/api/geocode?q=bandara sepinggan&lat=-0.502&lon=117.154');

        // No local match within 20 km, so the airport beyond the radius must
        // still surface through the country-wide search.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'geoapify')
            ->assertJsonPath('data.0.name', 'Bandara Sultan Aji Muhammad Sulaiman Sepinggan')
            ->assertJsonPath('data.0.address', 'Jalan Marsma R. Iswahyudi, Sepinggan, Balikpapan, Kalimantan Timur');
    }

    public function test_search_bounds_nominatim_to_reference_area(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->getJson('/api/geocode?q=sman&lat=-0.3813551&lon=117.1147299');

        // With a reference point, Nominatim must be bounded to the same search
        // area as Geoapify so its matches stay local.
        Http::assertSent(function ($request) {
            $url = rawurldecode($request->url());

            return str_contains($url, 'nominatim.openstreetmap.org')
                && str_contains($url, 'bounded=1')
                && str_contains($url, 'viewbox=');
        });
    }

    public function test_search_does_not_pad_results_with_nearby_places(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/geocode/autocomplete')) {
                return Http::response(['features' => []], 200);
            }

            return Http::response([
                'features' => [
                    [
                        'properties' => [
                            'lat' => -6.2705,
                            'lon' => 106.94,
                            'name' => 'Masjid At Taqwa',
                            'category' => 'amenity.place_of_worship',
                            'distance' => 120,
                        ],
                    ],
                    [
                        'properties' => [
                            'lat' => -6.2708,
                            'lon' => 106.939,
                            'name' => 'Kantor Kelurahan Jatikramat',
                            'category' => 'office.government',
                            'distance' => 300,
                        ],
                    ],
                ],
            ], 200);
        });

        $response = $this->getJson('/api/geocode?q=kantor&lat=-6.2705406&lon=106.9406&limit=4');

        $response->assertOk()
            ->assertJson(['data' => []]);

        // No nearby-place lookup may happen: results must only match the query.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'type=amenity'));
    }

    public function test_nearby_endpoint_returns_nearest_places(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'lat' => -6.2695,
                            'lon' => 106.941,
                            'name' => 'Alfamart',
                            'category' => 'commercial.convenience',
                            'distance' => 80,
                        ],
                    ],
                    [
                        'properties' => [
                            'lat' => -6.2705,
                            'lon' => 106.94,
                            'name' => 'Masjid At Taqwa',
                            'category' => 'amenity.place_of_worship',
                            'distance' => 120,
                        ],
                    ],
                    [
                        'properties' => [
                            'lat' => -6.271,
                            'lon' => 106.942,
                            'name' => 'Rumah Pompa IKIP',
                            'category' => 'building',
                            'distance' => 5,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/geocode/nearby?lat=-6.2705406&lon=106.9406&limit=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alfamart')
            ->assertJsonPath('data.0.distance', 80)
            ->assertJsonPath('data.1.name', 'Masjid At Taqwa')
            ->assertJsonPath('data.1.source', 'nearby')
            ->assertJsonPath('data.1.coordinate.latitude', -6.2705);
    }

    public function test_nearby_requires_lat_lon(): void
    {
        $this->getJson('/api/geocode/nearby')->assertStatus(422);
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

    public function test_reverse_uses_geoapify_when_key_present(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'lat' => -6.297,
                        'lon' => 106.94,
                        'result_type' => 'street',
                        'name' => 'Jalan Kelimutu',
                        'street' => 'Jalan Kelimutu',
                        'suburb' => 'Jatiasih',
                        'city' => 'Bekasi',
                        'county' => 'Kota Bekasi',
                        'formatted' => 'Jalan Kelimutu, Jatiasih, Bekasi 17422, JR, Indonesia',
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode/reverse?lat=-6.297&lon=106.94');

        $response->assertOk()
            ->assertJsonPath('data.source', 'geoapify')
            ->assertJsonPath('data.name', 'Jalan Kelimutu')
            ->assertJsonPath('data.address', 'Jalan Kelimutu, Jatiasih, Bekasi')
            ->assertJsonPath('data.coordinate.latitude', -6.297)
            ->assertJsonPath('data.coordinate.longitude', 106.94);

        Http::assertSent(function ($request) {
            $url = rawurldecode($request->url());

            return str_contains($url, 'api.geoapify.com/v1/geocode/reverse')
                && str_contains($url, 'lat=-6.297')
                && str_contains($url, 'lon=106.94')
                && str_contains($url, 'lang=id');
        });
    }

    public function test_reverse_falls_back_to_nominatim_when_geoapify_key_missing(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'name' => '',
                'display_name' => 'Jalan Kelimutu, Jatiasih, Jatimekar, Bekasi, Jawa Barat, Indonesia',
                'address' => [
                    'road' => 'Jalan Kelimutu',
                    'suburb' => 'Jatiasih',
                    'city' => 'Bekasi',
                    'state' => 'Jawa Barat',
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->getJson('/api/geocode/reverse?lat=-6.297&lon=106.94');

        $response->assertOk()
            ->assertJsonPath('data.source', 'nominatim')
            ->assertJsonPath('data.name', 'Jalan Kelimutu')
            ->assertJsonPath('data.address', 'Jalan Kelimutu, Jatiasih, Bekasi');
    }

    public function test_reverse_uses_place_name_when_no_road_available(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'lat' => -6.302,
                        'lon' => 106.945,
                        'result_type' => 'village',
                        'name' => 'Pamahan',
                        'city' => 'Bekasi',
                        'formatted' => 'Pamahan, Jatimekar, Bekasi, Indonesia',
                    ],
                ]],
            ], 200),
        ]);

        $this->getJson('/api/geocode/reverse?lat=-6.302&lon=106.945')
            ->assertOk()
            ->assertJsonPath('data.name', 'Pamahan')
            ->assertJsonPath('data.address', 'Pamahan, Bekasi');
    }

    public function test_reverse_prefers_nearby_place_with_dekat_prefix(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response([
                    'features' => [[
                        'properties' => [
                            'lat' => -6.2705406,
                            'lon' => 106.9406,
                            'name' => 'Masjid At Taqwa',
                            'category' => 'amenity.place_of_worship',
                            'distance' => 8,
                        ],
                    ]],
                ], 200);
            }

            return Http::response([
                'features' => [[
                    'properties' => [
                        'lat' => -6.2691906,
                        'lon' => 106.9406,
                        'name' => 'Jalan Ekonomi Raya',
                        'street' => 'Jalan Ekonomi Raya',
                        'suburb' => 'Jatikramat',
                        'city' => 'Bekasi',
                        'formatted' => 'Jalan Ekonomi Raya, Jatikramat, Bekasi, Indonesia',
                    ],
                ]],
            ], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.2691906&lon=106.9406')
            ->assertOk()
            ->assertJsonPath('data.name', 'Masjid At Taqwa')
            ->assertJsonPath('data.address', 'Dekat Masjid At Taqwa')
            ->assertJsonPath('data.source', 'geoapify');
    }

    public function test_reverse_uses_place_name_directly_when_exact(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response([
                    'features' => [[
                        'properties' => [
                            'lat' => -6.2705406,
                            'lon' => 106.9406,
                            'name' => 'Masjid At Taqwa',
                            'category' => 'amenity.place_of_worship',
                            'distance' => 3,
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['features' => []], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.2705406&lon=106.9406')
            ->assertOk()
            ->assertJsonPath('data.address', 'Masjid At Taqwa');
    }

    public function test_reverse_skips_low_value_places(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response([
                    'features' => [
                        [
                            'properties' => [
                                'name' => 'rumah bu zulaiha',
                                'category' => 'leisure.park',
                                'distance' => 10,
                            ],
                        ],
                        [
                            'properties' => [
                                'name' => 'Masjid Al Huda',
                                'category' => 'amenity.place_of_worship',
                                'distance' => 8,
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['features' => []], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.297&lon=106.94')
            ->assertOk()
            ->assertJsonPath('data.name', 'Masjid Al Huda')
            ->assertJsonPath('data.address', 'Dekat Masjid Al Huda');
    }

    public function test_reverse_falls_back_to_street_when_no_nearby_place(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response([
                    'features' => [[
                        'properties' => [
                            'name' => 'GS Supermarket Jatibening',
                            'category' => 'commercial.supermarket',
                            'distance' => 900,
                        ],
                    ]],
                ], 200);
            }

            return Http::response([
                'features' => [[
                    'properties' => [
                        'result_type' => 'street',
                        'name' => 'Jalan Kelimutu',
                        'street' => 'Jalan Kelimutu',
                        'suburb' => 'Jatiasih',
                        'city' => 'Bekasi',
                        'formatted' => 'Jalan Kelimutu, Jatiasih, Bekasi, Indonesia',
                    ],
                ]],
            ], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.297&lon=106.94')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jalan Kelimutu')
            ->assertJsonPath('data.address', 'Jalan Kelimutu, Jatiasih, Bekasi');
    }

    public function test_reverse_fallback_skips_place_names(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response(['features' => []], 200);
            }

            return Http::response([
                'features' => [
                    [
                        'properties' => [
                            'result_type' => 'amenity',
                            'name' => 'Indomaret',
                            'street' => 'Jalan Raya Jati Makmur',
                            'suburb' => 'Jatiasih',
                            'city' => 'Bekasi',
                        ],
                    ],
                    [
                        'properties' => [
                            'result_type' => 'street',
                            'name' => 'Jalan Raya Jati Makmur',
                            'street' => 'Jalan Raya Jati Makmur',
                            'suburb' => 'Jatiasih',
                            'city' => 'Bekasi',
                            'formatted' => 'Jalan Raya Jati Makmur, Jatiasih, Bekasi, Indonesia',
                        ],
                    ],
                ],
            ], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.290542&lon=106.9409253')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jalan Raya Jati Makmur')
            ->assertJsonPath('data.address', 'Jalan Raya Jati Makmur, Jatiasih, Bekasi');
    }

    public function test_reverse_falls_back_to_street_when_place_is_beyond_dekat_range(): void
    {
        config(['services.geoapify.key' => 'test-key']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'type=amenity')) {
                return Http::response([
                    'features' => [[
                        'properties' => [
                            'name' => 'Indomaret',
                            'category' => 'commercial.convenience',
                            'distance' => 150,
                        ],
                    ]],
                ], 200);
            }

            return Http::response([
                'features' => [[
                    'properties' => [
                        'result_type' => 'street',
                        'name' => 'Jalan Matematika 4',
                        'street' => 'Jalan Matematika 4',
                        'suburb' => 'Jatikramat',
                        'city' => 'Bekasi',
                        'formatted' => 'Jalan Matematika 4, Jatikramat, Bekasi, Indonesia',
                    ],
                ]],
            ], 200);
        });

        $this->getJson('/api/geocode/reverse?lat=-6.2705406&lon=106.9416')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jalan Matematika 4')
            ->assertJsonPath('data.address', 'Jalan Matematika 4, Jatikramat, Bekasi');
    }

    public function test_reverse_requires_lat_lon(): void
    {
        $this->getJson('/api/geocode/reverse')->assertStatus(422);
        $this->getJson('/api/geocode/reverse?lat=-6.297')->assertStatus(422);
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
