<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeocodeController extends Controller
{
    /**
     * Search places, in priority order:
     *   1. the app's own merchants (registered by merchant accounts),
     *   2. Geoapify (free, OpenStreetMap-based but with a much richer POI index),
     *   3. Nominatim as a last-resort fallback when Geoapify is unavailable.
     *
     * Results are restricted to a radius around the reference latitude/longitude
     * (Indonesia-wide without one) and sorted nearest-first when a reference is
     * provided. Only places that actually match the query are returned — results
     * are never padded with unrelated nearby places. When Geoapify returns fewer
     * matches than the limit, Nominatim tops the rest up so searches stay complete.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json(['message' => 'Parameter q wajib diisi.'], 422);
        }

        $limit = min(max((int) $request->query('limit', 6), 1), 10);
        $lat = filter_var($request->query('lat'), FILTER_VALIDATE_FLOAT);
        $lon = filter_var($request->query('lon'), FILTER_VALIDATE_FLOAT);
        $hasReference = $lat !== false && $lon !== false;
        $reference = $hasReference ? [$lat, $lon] : null;

        $results = $this->searchMerchants($query, $reference, $limit);
        $remaining = $limit - count($results);

        if ($remaining > 0) {
            $external = $this->searchGeoapify($query, $reference, $remaining);
            if ($external === null) {
                // Geoapify unreachable — fall back to Nominatim entirely.
                $external = $this->searchNominatim($query, $reference, $remaining);
            } elseif (count($external) < $remaining) {
                // Geoapify's POI index is thinner than the map data, so top
                // the rest up with Nominatim (OpenStreetMap) — searches stay
                // complete even for places missing from its index.
                $external = array_merge(
                    $external,
                    $this->searchNominatim($query, $reference, $remaining - count($external)),
                );
            }
            $results = array_merge($results, $external);
        }

        if ($hasReference) {
            // Only query matches belong in the results — never top up with the
            // places nearest to the user, even for vague queries. Restrict
            // everything to the search radius, drop duplicates across sources,
            // and sort the actual matches nearest-first.
            $results = $this->localizeResults($results, $lat, $lon);
        }

        return response()->json(['data' => $results]);
    }

    /**
     * List the named places nearest to a coordinate (mosques, offices, markets,
     * schools, ...) — used as recommendations on the location search screen.
     */
    public function nearby(Request $request): JsonResponse
    {
        $lat = filter_var($request->query('lat'), FILTER_VALIDATE_FLOAT);
        $lon = filter_var($request->query('lon'), FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false) {
            return response()->json(['message' => 'Parameter lat dan lon wajib diisi.'], 422);
        }

        $limit = min(max((int) $request->query('limit', 5), 1), 10);

        return response()->json(['data' => $this->nearbyPlaces($lat, $lon, $limit)]);
    }

    /**
     * Reverse-geocode a coordinate into a human-readable label, in priority
     * order:
     *   1. A nearby named place (mosque, minimarket, school, ...) — shown with
     *      its name when the user is essentially on top of it, or prefixed with
     *      "Dekat" (near) when it is close by,
     *   2. Geoapify reverse geocoding (when the API key is configured),
     *   3. Nominatim as a fallback when Geoapify is unavailable.
     */
    public function reverse(Request $request): JsonResponse
    {
        $lat = filter_var($request->query('lat'), FILTER_VALIDATE_FLOAT);
        $lon = filter_var($request->query('lon'), FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false) {
            return response()->json(['message' => 'Parameter lat dan lon wajib diisi.'], 422);
        }

        $nearby = $this->reverseNearbyPlace($lat, $lon);
        if ($nearby !== null) {
            return response()->json(['data' => $nearby]);
        }

        $result = $this->reverseGeoapify($lat, $lon);
        if ($result === null) {
            $result = $this->reverseNominatim($lat, $lon);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * The closest named place around the coordinate (e.g. "Masjid At Taqwa"),
     * used verbatim when the user is basically on top of it and prefixed with
     * "Dekat" when it is close by. Returns null when nothing useful is within
     * the near radius, so the caller falls back to a street/area label.
     */
    private function reverseNearbyPlace(float $lat, float $lon): ?array
    {
        $places = $this->nearbyPlaces($lat, $lon, 1, 'geoapify');
        $best = $places[0] ?? null;
        if ($best === null || $best['distance'] > self::NEAR_PLACE_DISTANCE) {
            return null;
        }

        $exact = $best['distance'] <= self::EXACT_PLACE_DISTANCE;

        return [
            'coordinate' => ['latitude' => $lat, 'longitude' => $lon],
            'name' => $best['name'],
            'address' => $exact ? $best['name'] : 'Dekat '.$best['name'],
            'source' => 'geoapify',
        ];
    }

    /**
     * The named places nearest to a coordinate, nearest first — low-value
     * features (private homes, utility buildings) are skipped.
     */
    private function nearbyPlaces(float $lat, float $lon, int $limit, string $source = 'nearby'): array
    {
        if ($limit <= 0) {
            return [];
        }

        $apiKey = config('services.geoapify.key');
        if (! $apiKey) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://api.geoapify.com/v1/geocode/reverse', [
                'lat' => $lat,
                'lon' => $lon,
                'apiKey' => $apiKey,
                'lang' => 'id',
                'type' => 'amenity',
                'limit' => $limit * 3,
            ]);

            if (! $response->ok()) {
                return [];
            }

            $features = $response->json('features') ?? [];
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($features)) {
            return [];
        }

        $candidates = [];
        foreach ($features as $feature) {
            $props = is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (! is_array($props)) {
                continue;
            }

            $name = trim((string) ($props['name'] ?? ''));
            if ($name === '' || $this->isLowValuePlace($name, (string) ($props['category'] ?? ''))) {
                continue;
            }

            $distance = filter_var($props['distance'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($distance === false || $distance < 0) {
                continue;
            }

            $itemLat = filter_var($props['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $itemLon = filter_var($props['lon'] ?? null, FILTER_VALIDATE_FLOAT);

            $candidates[] = [
                'coordinate' => [
                    'latitude' => $itemLat !== false ? $itemLat : $lat,
                    'longitude' => $itemLon !== false ? $itemLon : $lon,
                ],
                'name' => $name,
                'address' => $name,
                'distance' => (int) round($distance),
                'source' => $source,
            ];
        }

        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        // Geoapify sometimes returns the same place twice (e.g. with and without
        // a category); keep the nearest copy of each name.
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return array_slice($unique, 0, $limit);
    }

    private function isLowValuePlace(string $name, string $category): bool
    {
        // Structural/land features are not places people use as references.
        foreach (['building', 'man_made', 'place', 'natural', 'landuse', 'highway', 'barrier', 'military', 'industrial', 'craft'] as $prefix) {
            if (str_starts_with($category, $prefix)) {
                return true;
            }
        }

        // Private homes and utility buildings, but keep useful places like
        // "Rumah Makan" or "Rumah Sakit".
        if (preg_match('/^rumah\s+(?!makan|kopi|singgah|sakit)/i', $name)) {
            return true;
        }

        return (bool) preg_match('/^(gedung|gudang|pompa|gardu|gubuk)\b/i', $name);
    }

    private const EXACT_PLACE_DISTANCE = 5;  // meters — user is at the place → bare name
    private const NEAR_PLACE_DISTANCE = 50;  // meters — "Dekat <place>"; beyond → street/kelurahan/desa
    private const SEARCH_RADIUS = 20000;     // meters around the reference point
    private const ADDRESS_RESULT_TYPES = [
        'street', 'suburb', 'village', 'town', 'city', 'municipality',
        'county', 'district', 'neighbourhood', 'quarter', 'hamlet',
        'borough', 'state', 'postcode',
    ];

    private function reverseGeoapify(float $lat, float $lon): ?array
    {
        $apiKey = config('services.geoapify.key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://api.geoapify.com/v1/geocode/reverse', [
                'lat' => $lat,
                'lon' => $lon,
                'apiKey' => $apiKey,
                'lang' => 'id',
                'limit' => 5,
            ]);

            if (! $response->ok()) {
                return null;
            }

            $features = $response->json('features') ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($features)) {
            return null;
        }

        // Pick the nearest address-level feature (street, kelurahan/desa, city, ...)
        // and skip POI features: outside the "Dekat" radius the label must be the
        // street/kelurahan/desa, not a place name.
        $feature = null;
        foreach ($features as $candidate) {
            $candidateProps = is_array($candidate) ? ($candidate['properties'] ?? null) : null;
            $type = is_array($candidateProps) ? (string) ($candidateProps['result_type'] ?? '') : '';
            if (in_array($type, self::ADDRESS_RESULT_TYPES, true)) {
                $feature = $candidate;
                break;
            }
        }
        $props = is_array($feature) ? ($feature['properties'] ?? null) : null;
        if (! is_array($props)) {
            return null;
        }

        $name = trim((string) ($props['name'] ?? ''));
        $street = trim((string) ($props['street'] ?? ''));
        // Kelurahan/desa (suburb/village), then kecamatan (district).
        $area = trim((string) ($props['suburb'] ?? $props['village'] ?? $props['district'] ?? ''));
        $city = trim((string) ($props['city'] ?? $props['county'] ?? ''));
        $label = $this->compactLabel([$name ?: $street, $area, $city]);
        if ($label === null) {
            return null;
        }

        return [
            'coordinate' => ['latitude' => $lat, 'longitude' => $lon],
            'name' => $name !== '' ? $name : $street,
            'address' => $label,
            'source' => 'geoapify',
        ];
    }

    private function reverseNominatim(float $lat, float $lon): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $lat,
                'lon' => $lon,
                'format' => 'jsonv2',
                'addressdetails' => '1',
                'accept-language' => 'id',
                'zoom' => 18,
            ]);

            if (! $response->ok()) {
                return null;
            }

            $data = $response->json();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $road = trim((string) ($address['road'] ?? $address['pedestrian'] ?? $address['footway'] ?? ''));
        // Kelurahan/desa first, then kecamatan.
        $area = trim((string) ($address['neighbourhood'] ?? $address['suburb'] ?? $address['quarter'] ?? $address['village'] ?? $address['hamlet'] ?? ''));
        $city = trim((string) ($address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        $label = $this->compactLabel([$road, $area, $city]);
        if ($label === null && $name !== '') {
            $label = $name;
        }
        if ($label === null) {
            return null;
        }

        return [
            'coordinate' => ['latitude' => $lat, 'longitude' => $lon],
            'name' => $name !== '' ? $name : ($road !== '' ? $road : $label),
            'address' => $label,
            'source' => 'nominatim',
        ];
    }

    private function compactLabel(array $parts): ?string
    {
        $clean = array_values(array_filter(array_map(
            fn ($part) => trim((string) $part),
            $parts,
        ), fn (string $part) => $part !== ''));

        return $clean !== [] ? implode(', ', $clean) : null;
    }

    private function searchMerchants(string $query, ?array $reference, int $limit): array
    {
        $needle = mb_strtolower($query);

        $merchants = Merchant::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($builder) use ($needle) {
                $builder->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(address) LIKE ?', ["%{$needle}%"]);
            })
            ->limit($limit * 2)
            ->get(['name', 'address', 'latitude', 'longitude']);

        $scored = [];
        foreach ($merchants as $merchant) {
            $itemLat = (float) $merchant->latitude;
            $itemLon = (float) $merchant->longitude;
            $nameLower = mb_strtolower($merchant->name);

            // Exact name matches first, then names that start with the query.
            $score = $nameLower === $needle ? 0 : (str_starts_with($nameLower, $needle) ? 1 : 2);

            $scored[] = [
                'score' => $score,
                'row' => [
                    'coordinate' => ['latitude' => $itemLat, 'longitude' => $itemLon],
                    'name' => $merchant->name,
                    'address' => $merchant->address,
                    'distance' => $reference
                        ? $this->distanceMeters($reference[0], $reference[1], $itemLat, $itemLon)
                        : null,
                    'source' => 'merchant',
                ],
            ];
        }

        usort($scored, function (array $a, array $b) use ($reference): int {
            if ($a['score'] !== $b['score']) {
                return $a['score'] <=> $b['score'];
            }

            return $reference ? $a['row']['distance'] <=> $b['row']['distance'] : 0;
        });

        return array_column(array_slice($scored, 0, $limit), 'row');
    }

    private function searchGeoapify(string $query, ?array $reference, int $limit): ?array
    {
        $apiKey = config('services.geoapify.key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://api.geoapify.com/v1/geocode/autocomplete', array_filter([
                'text' => $query,
                'apiKey' => $apiKey,
                'lang' => 'id',
                'limit' => $limit,
                // With a reference point, restrict results to a radius around
                // it so searches surface local places instead of scattered ones.
                'filter' => $reference
                    ? 'circle:'.$reference[1].','.$reference[0].','.self::SEARCH_RADIUS
                    : 'countrycode:id',
                'bias' => $reference ? 'proximity:'.$reference[1].','.$reference[0] : null,
            ]));

            if (! $response->ok()) {
                return null;
            }

            $features = $response->json('features') ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($features)) {
            return [];
        }

        return $this->mapFeatures($features, $reference, 'geoapify');
    }

    private function searchNominatim(string $query, ?array $reference, int $limit): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'accept-language' => 'id',
            'countrycodes' => 'id',
            // Ask for more than needed: Nominatim ranks by importance, so the
            // nearest matches may sit past the requested limit; we re-rank by
            // distance anyway. Unbounded (country-wide) searches keep the
            // original limit.
            'limit' => $reference !== null ? min($limit * 4, 40) : $limit,
        ];
        if ($reference !== null) {
            // Restrict Nominatim to the same search area as Geoapify so its
            // matches stay local instead of surfacing same-name places on the
            // other side of Indonesia.
            [$refLat, $refLon] = $reference;
            $halfLat = self::SEARCH_RADIUS / 111_000.0;
            $halfLon = self::SEARCH_RADIUS / (111_000.0 * max(cos(deg2rad($refLat)), 0.01));
            $params['viewbox'] = sprintf(
                '%.6f,%.6f,%.6f,%.6f',
                $refLon - $halfLon,
                $refLat + $halfLat,
                $refLon + $halfLon,
                $refLat - $halfLat,
            );
            $params['bounded'] = '1';
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://nominatim.openstreetmap.org/search', $params);

            if (! $response->ok()) {
                return [];
            }

            $items = $response->json();
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($items)) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemLat = filter_var($item['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $itemLon = filter_var($item['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($itemLat === false || $itemLon === false) {
                continue;
            }

            $displayName = trim((string) ($item['display_name'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                $name = trim(explode(',', $displayName)[0] ?? '');
            }

            $results[] = [
                'coordinate' => ['latitude' => $itemLat, 'longitude' => $itemLon],
                'name' => $name,
                'address' => $this->buildAddress($displayName, $name),
                'distance' => $reference
                    ? $this->distanceMeters($reference[0], $reference[1], $itemLat, $itemLon)
                    : null,
                'source' => 'nominatim',
            ];
        }

        return $results;
    }

    private function mapFeatures(array $features, ?array $reference, string $source): array
    {
        $results = [];
        foreach ($features as $feature) {
            $props = is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (! is_array($props)) {
                continue;
            }

            $itemLat = filter_var($props['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $itemLon = filter_var($props['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($itemLat === false || $itemLon === false) {
                continue;
            }

            $formatted = trim((string) ($props['formatted'] ?? ''));
            $name = trim((string) ($props['name'] ?? ''));
            if ($name === '') {
                $name = trim(explode(',', $formatted)[0] ?? '');
            }

            $results[] = [
                'coordinate' => ['latitude' => $itemLat, 'longitude' => $itemLon],
                'name' => $name,
                'address' => $this->buildAddress($formatted, $name),
                'distance' => $reference
                    ? $this->distanceMeters($reference[0], $reference[1], $itemLat, $itemLon)
                    : null,
                'source' => $source,
            ];
        }

        return $results;
    }

    /**
     * Keep only results within the search radius around the reference point,
     * drop duplicates (the same place can come back from several sources), and
     * sort the remaining matches nearest-first.
     */
    private function localizeResults(array $results, float $lat, float $lon): array
    {
        $unique = [];
        foreach ($results as $item) {
            $itemLat = (float) ($item['coordinate']['latitude'] ?? $lat);
            $itemLon = (float) ($item['coordinate']['longitude'] ?? $lon);
            $distance = $item['distance'] ?? $this->distanceMeters($lat, $lon, $itemLat, $itemLon);
            if ($distance > self::SEARCH_RADIUS) {
                continue;
            }
            $item['distance'] = $distance;
            $key = mb_strtolower(trim((string) ($item['name'] ?? '')));
            if ($key === '') {
                $unique[] = $item;
                continue;
            }
            if (! isset($unique[$key]) || $unique[$key]['distance'] > $distance) {
                $unique[$key] = $item;
            }
        }
        $list = array_values($unique);
        usort($list, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $list;
    }

    private function buildAddress(string $displayName, string $name): string
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $displayName)),
            fn (string $part) => $part !== '' && mb_strtolower($part) !== mb_strtolower($name),
        ));

        if (($parts[count($parts) - 1] ?? '') === 'Indonesia') {
            array_pop($parts);
        }

        return $parts !== [] ? implode(', ', $parts) : $displayName;
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $radius = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return (int) round($radius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
