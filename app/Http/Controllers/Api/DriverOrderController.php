<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Services\OrderPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverOrderController extends Controller
{    public function active(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        // Use OR for each (type, status) pair — much more efficient than
        // nested where/orWhere which forces an index-merge or full scan.
        $activeStatuses = collect([
            [Order::TYPE_RIDE, [Order::STATUS_DRIVER_ASSIGNED, Order::STATUS_DRIVER_ARRIVED, Order::STATUS_IN_PROGRESS]],
            [Order::TYPE_SEND, [Order::STATUS_DRIVER_ASSIGNED, Order::STATUS_DRIVER_ARRIVED, Order::STATUS_PICKED_UP, Order::STATUS_DELIVERING]],
            [Order::TYPE_FOOD, [Order::STATUS_DRIVER_ASSIGNED, Order::STATUS_PICKED_UP, Order::STATUS_DELIVERING]],
        ]);

        $order = Order::with([
            'user:id,name',
            'merchant:id,name',
        ])
            ->select([
                'id', 'order_number', 'type', 'status',
                'pickup_address', 'destination_address', 'total_price', 'driver_id', 'merchant_id',
                'user_id', 'created_at',
            ])
            ->where('driver_id', $driver->id)
            ->where(function ($query) use ($activeStatuses) {
                foreach ($activeStatuses as [$type, $statuses]) {
                    $query->orWhere(fn ($q) => $q->where('type', $type)->whereIn('status', $statuses));
                }
            })
            ->latest()
            ->first();

        return response()->json([
            'order' => $order,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        return response()->json(
            Order::with(['user:id,name'])
                ->select([
                    'id', 'order_number', 'type', 'status',
                    'pickup_address', 'destination_address',
                    'total_price', 'created_at', 'completed_at',
                    'user_id', 'driver_id',
                ])
                ->where('driver_id', $driver->id)
                ->whereIn('type', [Order::TYPE_RIDE, Order::TYPE_SEND, Order::TYPE_FOOD])
                ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
                ->latest()
                ->paginate(10)
        );
    }    public function available(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)
            ->where('status', 'approved')
            ->where('is_online', true)
            ->with(['location', 'vehicle'])
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

        $driverLat = (float) $driver->location->latitude;
        $driverLng = (float) $driver->location->longitude;

        // Haversine distance formula in SQL — avoids loading all orders
        // into PHP and calculating distance there.  The formula returns
        // the approximate distance in kilometres.
        $earthRadius = 6371; // km
        $latDiff = 'RADIANS(pickup_latitude - ' . $driverLat . ')';
        $lngDiff = 'RADIANS(pickup_longitude - ' . $driverLng . ')';
        $haversineA = "({latDiff}/2) * ({latDiff}/2) + COS(RADIANS({driverLat})) * COS(RADIANS(pickup_latitude)) * ({lngDiff}/2) * ({lngDiff}/2)";
        $haversineA = str_replace(['{latDiff}', '{lngDiff}', '{driverLat}'], [$latDiff, $lngDiff, $driverLat], $haversineA);
        $haversineC = "2 * ATAN2(SQRT({a}), SQRT(1 - {a}))";
        $haversineC = str_replace('{a}', $haversineA, $haversineC);
        $distanceExpr = "({earthRadius} * {$haversineC})";
        $distanceExpr = str_replace('{earthRadius}', $earthRadius, $distanceExpr);

        $orders = Order::with(['merchant'])
            ->select([
                'id', 'order_number', 'type', 'service_variant', 'vehicle_type',
                'pickup_address', 'pickup_latitude', 'pickup_longitude',
                'destination_address', 'total_price', 'status', 'created_at', 'merchant_id',
            ])
            ->selectRaw("ROUND({$distanceExpr}, 2) AS pickup_distance")
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('type', [Order::TYPE_RIDE, Order::TYPE_SEND])
                        ->where('status', Order::STATUS_SEARCHING_DRIVER);
                })->orWhere(function ($query) {
                    $query->where('type', Order::TYPE_SEND)
                        ->whereIn('status', [
                            Order::STATUS_DRIVER_ASSIGNED,
                            Order::STATUS_DRIVER_ARRIVED,
                            Order::STATUS_PICKED_UP,
                            Order::STATUS_DELIVERING,
                        ]);
                })->orWhere(function ($query) {
                    $query->where('type', Order::TYPE_FOOD)
                        ->where('status', Order::STATUS_READY_FOR_PICKUP);
                });
            })
            ->whereNull('driver_id')
            ->when($driver->vehicle?->type, fn ($query, $vehicleType) => $query->where(fn ($orders) => $orders->whereNull('vehicle_type')->orWhere('vehicle_type', $vehicleType)))
            ->havingRaw('pickup_distance <= ?', [10])
            ->orderByRaw('pickup_distance ASC')
            ->limit(50)
            ->get();

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

        // Safety net for SIMs that expired while the driver was online.
        $activeVehicle = $driver->vehicle;
        if ($activeVehicle) {
            $docType = $activeVehicle->type === 'car' ? 'sim_a' : 'sim_c';
            $simDoc = $driver->documents()->where('type', $docType)->first();
            if ($simDoc && $simDoc->expires_at?->isPast()) {
                return response()->json([
                    'message' => strtoupper(str_replace('_', ' ', $docType)).' sudah kedaluwarsa. Perbarui SIM Anda di Dokumen & SIM.',
                ], 422);
            }
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

            if ($lockedOrder->vehicle_type !== null && $driver->vehicle?->type !== null && $driver->vehicle->type !== $lockedOrder->vehicle_type) {
                return [
                    'error' => 'This order requires a different vehicle type.',
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
                'vehicle_id' => $driver->active_vehicle_id,
                'vehicle_snapshot' => $driver->vehicle ? [
                    'id' => $driver->vehicle->id, 'type' => $driver->vehicle->type,
                    'brand' => $driver->vehicle->brand, 'model' => $driver->vehicle->model,
                    'plate_number' => $driver->vehicle->plate_number, 'color' => $driver->vehicle->color,
                ] : null,
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
                    'merchant',
                    'items',
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

        app(OrderPushNotificationService::class)->driverAssigned($result['order']);

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
