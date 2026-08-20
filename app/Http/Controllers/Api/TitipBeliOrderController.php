<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TitipBeliLocation;
use App\Services\OrderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TitipBeliOrderController extends Controller
{
    public function __construct(private readonly OrderPricingService $pricing) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_locations' => ['required', 'array', 'min:1'],
            'purchase_locations.*.place_name' => ['required', 'string', 'max:255'],
            'purchase_locations.*.address' => ['required', 'string', 'max:500'],
            'purchase_locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'purchase_locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'purchase_locations.*.items' => ['required', 'array', 'min:1'],
            'purchase_locations.*.items.*.name' => ['required', 'string', 'max:255'],
            'purchase_locations.*.items.*.quantity' => ['nullable', 'string', 'max:100'],
            'purchase_locations.*.items.*.note' => ['nullable', 'string', 'max:500'],
            'destination_address' => ['required', 'string', 'max:500'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'advance_amount' => ['required', 'numeric', 'min:0'],
            'driver_note' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['sometimes', 'in:cash'],
        ]);

        // Calculate multi-stop distance: first location → last location → destination
        $locations = $validated['purchase_locations'];
        $totalDistance = 0.0;

        for ($i = 0; $i < count($locations) - 1; $i++) {
            $totalDistance += $this->pricing->distance(
                (float) $locations[$i]['latitude'],
                (float) $locations[$i]['longitude'],
                (float) $locations[$i + 1]['latitude'],
                (float) $locations[$i + 1]['longitude'],
            );
        }

        // Last location to destination
        $lastLoc = $locations[count($locations) - 1];
        $totalDistance += $this->pricing->distance(
            (float) $lastLoc['latitude'],
            (float) $lastLoc['longitude'],
            (float) $validated['destination_latitude'],
            (float) $validated['destination_longitude'],
        );

        $total = $this->pricing->sendFare($totalDistance, 'motorcycle');

        $order = DB::transaction(function () use ($request, $validated, $totalDistance, $total, $locations) {
            $order = Order::create([
                'order_number' => 'AGT-'.strtoupper(Str::random(10)),
                'user_id' => $request->user()->id,
                'type' => Order::TYPE_TITIP_BELI,
                'service_variant' => 'delivery',
                'vehicle_type' => 'motorcycle',
                'pickup_address' => $locations[0]['address'],
                'pickup_latitude' => $locations[0]['latitude'],
                'pickup_longitude' => $locations[0]['longitude'],
                'destination_address' => $validated['destination_address'],
                'destination_latitude' => $validated['destination_latitude'],
                'destination_longitude' => $validated['destination_longitude'],
                'distance' => round($totalDistance, 2),
                'subtotal' => $total,
                'delivery_fee' => 0,
                'service_fee' => 0,
                'total_price' => $total,
                'advance_amount' => $validated['advance_amount'],
                'driver_note' => $validated['driver_note'] ?? null,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'status' => Order::STATUS_SEARCHING_DRIVER,
            ]);

            // Create purchase locations and items
            foreach ($locations as $seq => $loc) {
                $location = $order->titipBeliLocations()->create([
                    'sequence' => $seq + 1,
                    'place_name' => $loc['place_name'],
                    'address' => $loc['address'],
                    'latitude' => $loc['latitude'],
                    'longitude' => $loc['longitude'],
                ]);

                foreach ($loc['items'] as $item) {
                    $location->items()->create([
                        'name' => $item['name'],
                        'quantity' => $item['quantity'] ?? null,
                        'note' => $item['note'] ?? null,
                    ]);
                }
            }

            $order->statusHistories()->create([
                'status' => Order::STATUS_SEARCHING_DRIVER,
                'note' => 'Customer created a Buy for Me request.',
                'created_at' => now(),
            ]);

            $order->payment()->create([
                'method' => 'cash',
                'status' => 'pending',
                'amount' => $total,
            ]);

            return $order;
        }, 3);

        return response()->json([
            'message' => 'Buy for Me order created successfully.',
            'order' => $order->load(['payment', 'statusHistories', 'titipBeliLocations.items']),
            'fare' => $this->pricing->sendBreakdown($totalDistance, 'motorcycle'),
        ], 201);
    }
}
