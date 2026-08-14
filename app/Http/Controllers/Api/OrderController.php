<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderPricingService;
use App\Services\OrderPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
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
            'estimated_duration' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $distance = $this->pricing->distance(
            (float) $validated['pickup_latitude'],
            (float) $validated['pickup_longitude'],
            (float) $validated['destination_latitude'],
            (float) $validated['destination_longitude']
        );

        $totalPrice = $this->pricing->rideFare($distance);

        $order = DB::transaction(function () use (
            $request,
            $validated,
            $distance,
            $totalPrice
        ) {
            $order = Order::create([
                'order_number' => 'AG-'.strtoupper(Str::random(10)),
                'user_id' => $request->user()->id,
                'driver_id' => null,
                'merchant_id' => null,
                'type' => Order::TYPE_RIDE,
                'pickup_address' => $validated['pickup_address'],
                'pickup_latitude' => $validated['pickup_latitude'],
                'pickup_longitude' => $validated['pickup_longitude'],
                'destination_address' => $validated['destination_address'],
                'destination_latitude' => $validated['destination_latitude'],
                'destination_longitude' => $validated['destination_longitude'],
                'distance' => round($distance, 2),
                'estimated_duration' => $validated['estimated_duration'] ?? null,
                'subtotal' => $totalPrice,
                'delivery_fee' => 0,
                'service_fee' => 0,
                'total_price' => $totalPrice,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'status' => Order::STATUS_SEARCHING_DRIVER,
                'notes' => $validated['notes'] ?? null,
            ]);

            $order->statusHistories()->create([
                'status' => Order::STATUS_SEARCHING_DRIVER,
                'note' => 'Customer created a ride request.',
                'created_at' => now(),
            ]);

            $order->payment()->create([
                'method' => 'cash',
                'status' => 'pending',
                'amount' => $totalPrice,
            ]);

            return $order;
        });

        return response()->json([
            'message' => 'Ride request created successfully.',
            'order' => $order->load(['payment', 'statusHistories']),
            'fare' => $this->pricing->rideBreakdown($distance),

        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::with([
            'driver.user',
            'driver.vehicle',
            'merchant',
            'statusHistories',
        ])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $canView = $order->user_id === $user->id
            || $order->driver?->user_id === $user->id
            || $order->merchant?->user_id === $user->id
            || $user->hasRole('admin');

        if (! $canView) {
            return response()->json([
                'message' => 'Order not found.',
            ], 404);
        }

        return response()->json([
            'order' => $order->load([
                'user',
                'driver.user',
                'driver.vehicle',
                'driver.location',
                'merchant',
                'items.product',
                'payment',
                'rating',
                'statusHistories',
            ]),
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Order not found.',
            ], 404);
        }

        $cancellableStatuses = $order->type === Order::TYPE_FOOD
            ? [Order::STATUS_PENDING]
            : [Order::STATUS_SEARCHING_DRIVER, Order::STATUS_DRIVER_ASSIGNED];

        if (! in_array($order->status, $cancellableStatuses, true)) {
            return response()->json([
                'message' => 'This order cannot be cancelled.',
            ], 422);
        }

        $validated = $request->validate([
            'cancelled_reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($order, $validated) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $cancellableStatuses = $lockedOrder->type === Order::TYPE_FOOD
                ? [Order::STATUS_PENDING]
                : [Order::STATUS_SEARCHING_DRIVER, Order::STATUS_DRIVER_ASSIGNED];

            if (! in_array($lockedOrder->status, $cancellableStatuses, true)) {
                abort(422, 'This order cannot be cancelled.');
            }

            if ($lockedOrder->type === Order::TYPE_FOOD) {
                $lockedOrder->load('items');
                foreach ($lockedOrder->items as $item) {
                    Product::whereKey($item->product_id)
                        ->increment('stock', $item->quantity);
                }
            }

            $lockedOrder->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_reason' => $validated['cancelled_reason'] ?? null,
            ]);

            $lockedOrder->statusHistories()->create([
                'status' => Order::STATUS_CANCELLED,
                'note' => $validated['cancelled_reason'] ?? 'Cancelled by customer.',
                'created_at' => now(),
            ]);
        });

        $cancelledOrder = $order->fresh(['driver.user', 'merchant.user', 'statusHistories']);
        app(OrderPushNotificationService::class)->customerCancelled($cancelledOrder);

        return response()->json([
            'message' => 'Order cancelled successfully.',
            'order' => $cancelledOrder,
        ]);
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)
            ->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        if ($order->driver_id !== $driver->id) {
            return response()->json([
                'message' => 'You are not assigned to this order.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => [
                'required',
                'in:driver_arrived,in_progress,picked_up,delivering,completed',
            ],
        ]);

        $currentStatus = $order->status;
        $newStatus = $validated['status'];

        $allowedTransitions = match ($order->type) {
            Order::TYPE_FOOD => [
                Order::STATUS_DRIVER_ASSIGNED => [Order::STATUS_PICKED_UP],
                Order::STATUS_PICKED_UP => [Order::STATUS_DELIVERING],
                Order::STATUS_DELIVERING => [Order::STATUS_COMPLETED],
            ],
            Order::TYPE_SEND => [
                Order::STATUS_DRIVER_ASSIGNED => [Order::STATUS_DRIVER_ARRIVED],
                Order::STATUS_DRIVER_ARRIVED => [Order::STATUS_PICKED_UP],
                Order::STATUS_PICKED_UP => [Order::STATUS_DELIVERING],
                Order::STATUS_DELIVERING => [Order::STATUS_COMPLETED],
            ],
            default => [
                Order::STATUS_DRIVER_ASSIGNED => [Order::STATUS_DRIVER_ARRIVED],
                Order::STATUS_DRIVER_ARRIVED => [Order::STATUS_IN_PROGRESS],
                Order::STATUS_IN_PROGRESS => [Order::STATUS_COMPLETED],
            ],
        };

        if (
            ! isset($allowedTransitions[$currentStatus]) ||
            ! in_array($newStatus, $allowedTransitions[$currentStatus], true)
        ) {
            return response()->json([
                'message' => "Cannot change order status from {$currentStatus} to {$newStatus}.",
            ], 422);
        }

        $notes = [
            'driver_arrived' => 'Driver has arrived at pickup location.',
            'in_progress' => 'Trip has started.',
            'picked_up' => $order->type === Order::TYPE_SEND
                ? 'Package has been picked up from the customer.'
                : 'Order has been picked up from the merchant.',
            'delivering' => $order->type === Order::TYPE_SEND
                ? 'Package is being delivered to the recipient.'
                : 'Order is being delivered.',
            'completed' => match ($order->type) {
                Order::TYPE_FOOD => 'Food order delivered successfully.',
                Order::TYPE_SEND => 'Package delivered successfully.',
                default => 'Trip completed successfully.',
            },
        ];

        DB::transaction(function () use ($order, $currentStatus, $newStatus, $allowedTransitions, $notes, $driver) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== $currentStatus
                || ! in_array($newStatus, $allowedTransitions[$lockedOrder->status] ?? [], true)) {
                abort(422, 'The order status changed. Please refresh and try again.');
            }

            $lockedOrder->update([
                'status' => $newStatus,
                'completed_at' => $newStatus === Order::STATUS_COMPLETED ? now() : null,
            ]);

            $lockedOrder->statusHistories()->create([
                'status' => $newStatus,
                'note' => $notes[$newStatus],
                'created_at' => now(),
            ]);

            if ($newStatus === Order::STATUS_COMPLETED) {
                $driver->increment('total_completed_orders');
            }
        });

        $updatedOrder = $order->fresh([
            'user',
            'driver.user',
            'driver.vehicle',
            'driver.location',
            'merchant.user',
            'items',
            'statusHistories',
        ]);
        app(OrderPushNotificationService::class)->driverStatusChanged($updatedOrder);

        return response()->json([
            'message' => 'Order status updated successfully.',
            'order' => $updatedOrder,
        ]);
    }
}
