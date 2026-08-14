<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendOrderController extends Controller
{
    public function __construct(private readonly OrderPricingService $pricing)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['required', 'string', 'max:500'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'item_name' => ['required', 'string', 'max:150'],
            'item_description' => ['nullable', 'string', 'max:1000'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_phone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['sometimes', 'in:cash'],
        ]);

        $distance = $this->pricing->distance(
            (float) $validated['pickup_latitude'],
            (float) $validated['pickup_longitude'],
            (float) $validated['destination_latitude'],
            (float) $validated['destination_longitude'],
        );
        $total = $this->pricing->sendFare($distance);

        $order = DB::transaction(function () use ($request, $validated, $distance, $total) {
            $order = Order::create([
                'order_number' => 'AGS-'.strtoupper(Str::random(10)),
                'user_id' => $request->user()->id,
                'type' => Order::TYPE_SEND,
                'pickup_address' => $validated['pickup_address'],
                'pickup_latitude' => $validated['pickup_latitude'],
                'pickup_longitude' => $validated['pickup_longitude'],
                'destination_address' => $validated['destination_address'],
                'destination_latitude' => $validated['destination_latitude'],
                'destination_longitude' => $validated['destination_longitude'],
                'distance' => round($distance, 2),
                'subtotal' => $total,
                'delivery_fee' => 0,
                'service_fee' => 0,
                'total_price' => $total,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'status' => Order::STATUS_SEARCHING_DRIVER,
                'notes' => json_encode([
                    'version' => 1,
                    'item_name' => $validated['item_name'],
                    'item_description' => $validated['item_description'] ?? null,
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'notes' => $validated['notes'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            $order->statusHistories()->create([
                'status' => Order::STATUS_SEARCHING_DRIVER,
                'note' => 'Customer created a Send request.',
                'created_at' => now(),
            ]);
            $order->payment()->create(['method' => 'cash', 'status' => 'pending', 'amount' => $total]);

            return $order;
        }, 3);

        return response()->json([
            'message' => 'Send request created successfully.',
            'order' => $order->load(['payment', 'statusHistories']),
            'fare' => $this->pricing->sendBreakdown($distance),
        ], 201);
    }
}
