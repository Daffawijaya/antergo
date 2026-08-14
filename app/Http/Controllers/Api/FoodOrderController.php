<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FoodOrderController extends Controller
{
    private const BASE_DELIVERY_FEE = 5000;

    private const DELIVERY_FEE_PER_KM = 2500;

    private const MINIMUM_DELIVERY_FEE = 8000;

    private const SERVICE_FEE = 1000;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'destination_address' => ['required', 'string', 'max:500'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'payment_method' => ['sometimes', 'in:cash,qris,ewallet,gateway'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = DB::transaction(function () use ($request, $validated) {
            $merchant = Merchant::whereKey($validated['merchant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $merchant->is_active || ! $merchant->is_open) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['The merchant is currently unavailable.'],
                ]);
            }

            if ($merchant->latitude === null || $merchant->longitude === null) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['The merchant location is not configured.'],
                ]);
            }

            $requestedItems = collect($validated['items'])->keyBy('product_id');
            $productIds = $requestedItems->keys()->sort()->values();
            $products = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['One or more products do not exist.'],
                ]);
            }

            $subtotal = 0;
            $snapshots = [];

            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                $quantity = (int) $requestedItems->get($productId)['quantity'];

                if ($product->merchant_id !== $merchant->id || ! $product->is_available) {
                    throw ValidationException::withMessages([
                        'items' => ["Product {$productId} is not available from this merchant."],
                    ]);
                }

                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$product->name}."],
                    ]);
                }

                $itemSubtotal = (float) $product->price * $quantity;
                $subtotal += $itemSubtotal;
                $snapshots[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $product->price,
                    'quantity' => $quantity,
                    'subtotal' => $itemSubtotal,
                ];

                $product->decrement('stock', $quantity);
            }

            $distance = $this->calculateDistance(
                (float) $merchant->latitude,
                (float) $merchant->longitude,
                (float) $validated['destination_latitude'],
                (float) $validated['destination_longitude'],
            );
            $deliveryFee = $this->calculateDeliveryFee($distance);
            $total = $subtotal + $deliveryFee + self::SERVICE_FEE;

            $order = Order::create([
                'order_number' => 'AGF-'.strtoupper(Str::random(10)),
                'user_id' => $request->user()->id,
                'merchant_id' => $merchant->id,
                'type' => Order::TYPE_FOOD,
                'pickup_address' => $merchant->address,
                'pickup_latitude' => $merchant->latitude,
                'pickup_longitude' => $merchant->longitude,
                'destination_address' => $validated['destination_address'],
                'destination_latitude' => $validated['destination_latitude'],
                'destination_longitude' => $validated['destination_longitude'],
                'distance' => round($distance, 2),
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => self::SERVICE_FEE,
                'total_price' => $total,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'payment_status' => 'pending',
                'status' => Order::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
            ]);

            $order->items()->createMany($snapshots);
            $order->statusHistories()->create([
                'status' => Order::STATUS_PENDING,
                'note' => 'Food order created and awaiting merchant confirmation.',
                'created_at' => now(),
            ]);

            return $order;
        }, 3);

        return response()->json([
            'message' => 'Food order created successfully.',
            'order' => $order->load(['merchant', 'items', 'statusHistories']),
        ], 201);
    }

    public function merchantOrders(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        if (! $merchant) {
            return response()->json(['message' => 'Merchant not found.'], 404);
        }

        return response()->json(
            Order::with(['user', 'items', 'driver.user', 'statusHistories'])
                ->where('merchant_id', $merchant->id)
                ->where('type', Order::TYPE_FOOD)
                ->latest()
                ->paginate(20)
        );
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        return $this->transitionMerchantOrder(
            $request,
            $order,
            Order::STATUS_PENDING,
            Order::STATUS_MERCHANT_CONFIRMED,
            'Food order confirmed by merchant.',
        );
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:preparing,ready_for_pickup'],
        ]);

        $from = $validated['status'] === Order::STATUS_PREPARING
            ? Order::STATUS_MERCHANT_CONFIRMED
            : Order::STATUS_PREPARING;
        $note = $validated['status'] === Order::STATUS_PREPARING
            ? 'Merchant started preparing the order.'
            : 'Order is ready for driver pickup.';

        return $this->transitionMerchantOrder(
            $request,
            $order,
            $from,
            $validated['status'],
            $note,
        );
    }

    private function transitionMerchantOrder(
        Request $request,
        Order $order,
        string $from,
        string $to,
        string $note,
    ): JsonResponse {
        $merchant = $request->user()->merchant;

        if (! $merchant || $order->merchant_id !== $merchant->id || $order->type !== Order::TYPE_FOOD) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $updatedOrder = DB::transaction(function () use ($order, $from, $to, $note) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== $from) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot change order status from {$lockedOrder->status} to {$to}."],
                ]);
            }

            $lockedOrder->update(['status' => $to]);
            $lockedOrder->statusHistories()->create([
                'status' => $to,
                'note' => $note,
                'created_at' => now(),
            ]);

            return $lockedOrder;
        }, 3);

        return response()->json([
            'message' => 'Order status updated successfully.',
            'order' => $updatedOrder->fresh(['user', 'items', 'driver.user', 'statusHistories']),
        ]);
    }

    private function calculateDeliveryFee(float $distance): int
    {
        $fee = self::BASE_DELIVERY_FEE + ($distance * self::DELIVERY_FEE_PER_KM);

        return (int) max(self::MINIMUM_DELIVERY_FEE, round($fee / 500) * 500);
    }

    private function calculateDistance(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2,
    ): float {
        $earthRadius = 6371;
        $latitudeDifference = deg2rad($latitude2 - $latitude1);
        $longitudeDifference = deg2rad($longitude2 - $longitude1);
        $a = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2))
            * sin($longitudeDifference / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
