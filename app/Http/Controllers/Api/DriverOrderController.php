<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverOrderController extends Controller
{
    public function available(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)
            ->where('status', 'approved')
            ->where('is_online', true)
            ->with('location')
            ->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver is not online or not approved.',
            ], 403);
        }

        if (! $driver->location) {
            return response()->json([
                'message' => 'Driver location is not available.',
            ], 422);
        }

        $driverLatitude = (float) $driver->location->latitude;
        $driverLongitude = (float) $driver->location->longitude;

        $orders = Order::query()
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('type', [Order::TYPE_RIDE, Order::TYPE_SEND])
                        ->where('status', Order::STATUS_SEARCHING_DRIVER);
                })->orWhere(function ($query) {
                    $query->where('type', Order::TYPE_FOOD)
                        ->where('status', Order::STATUS_READY_FOR_PICKUP);
                });
            })
            ->whereNull('driver_id')
            ->latest()
            ->get();

        $orders = $orders
            ->map(function (Order $order) use ($driverLatitude, $driverLongitude) {
                $orderLatitude = (float) $order->pickup_latitude;
                $orderLongitude = (float) $order->pickup_longitude;

                $distance = $this->calculateDistance(
                    $driverLatitude,
                    $driverLongitude,
                    $orderLatitude,
                    $orderLongitude
                );

                $order->pickup_distance = round($distance, 2);

                return $order;
            })
            ->filter(fn (Order $order) => $order->pickup_distance <= 10)
            ->sortBy('pickup_distance')
            ->values();

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)
            ->where('status', 'approved')
            ->where('is_online', true)
            ->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver is not online or not approved.',
            ], 403);
        }

        $result = DB::transaction(function () use ($driver, $order) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return [
                    'error' => 'Order not found.',
                    'status' => 404,
                ];
            }

            $expectedStatus = $lockedOrder->type === Order::TYPE_FOOD
                ? Order::STATUS_READY_FOR_PICKUP
                : Order::STATUS_SEARCHING_DRIVER;

            if ($lockedOrder->status !== $expectedStatus) {
                return [
                    'error' => 'This order has already been taken or is no longer available.',
                    'status' => 422,
                ];
            }

            if ($lockedOrder->driver_id !== null) {
                return [
                    'error' => 'This order has already been assigned to another driver.',
                    'status' => 422,
                ];
            }

            $lockedOrder->update([
                'driver_id' => $driver->id,
                'status' => Order::STATUS_DRIVER_ASSIGNED,
            ]);

            $lockedOrder->statusHistories()->create([
                'status' => Order::STATUS_DRIVER_ASSIGNED,
                'note' => 'Order accepted by driver.',
                'created_at' => now(),
            ]);

            return [
                'order' => $lockedOrder->fresh([
                    'user',
                    'driver.user',
                    'driver.vehicle',
                    'driver.location',
                    'statusHistories',
                ]),
                'status' => 200,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Order accepted successfully.',
            'order' => $result['order'],
        ]);
    }

    private function calculateDistance(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {
        $earthRadius = 6371;

        $latitudeDifference = deg2rad($latitude2 - $latitude1);
        $longitudeDifference = deg2rad($longitude2 - $longitude1);

        $a = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad($latitude1))
            * cos(deg2rad($latitude2))
            * sin($longitudeDifference / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
