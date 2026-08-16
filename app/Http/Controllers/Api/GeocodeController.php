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
     * Results are restricted to Indonesia and sorted nearest-first when a
     * reference latitude/longitude is provided.
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
                $external = $this->searchNominatim($query, $reference, $remaining);
            }
            $results = array_merge($results, $external);
        }

        if ($hasReference) {
            usort($results, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        }

        return response()->json(['data' => $results]);
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
                'filter' => 'countrycode:id',
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
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AnterGoApp/1.0 (geocoding proxy)',
            ])->timeout(8)->acceptJson()->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => '1',
                'accept-language' => 'id',
                'countrycodes' => 'id',
                'limit' => $limit,
            ]);

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
