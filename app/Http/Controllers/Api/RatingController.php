<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RatingController extends Controller
{
    public function store(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'target' => ['required', Rule::in(['driver', 'merchant'])],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $rating = DB::transaction(function () use ($order, $request, $validated) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['order' => ['Only a completed order can be rated.']]);
            }
            if ($lockedOrder->payment_status !== 'paid') {
                throw ValidationException::withMessages(['payment' => ['Cash payment must be settled before rating.']]);
            }
            if ($lockedOrder->rating()->exists()) {
                throw ValidationException::withMessages(['rating' => ['This order has already been rated.']]);
            }
            if ($validated['target'] === 'driver' && $lockedOrder->driver_id === null) {
                throw ValidationException::withMessages(['target' => ['The assigned driver is not available.']]);
            }
            if ($validated['target'] === 'merchant'
                && ($lockedOrder->type !== Order::TYPE_FOOD || $lockedOrder->merchant_id === null)) {
                throw ValidationException::withMessages(['target' => ['Merchant rating is only available for a Food order.']]);
            }

            $rating = $lockedOrder->rating()->create([
                'user_id' => $request->user()->id,
                'driver_id' => $validated['target'] === 'driver' ? $lockedOrder->driver_id : null,
                'merchant_id' => $validated['target'] === 'merchant' ? $lockedOrder->merchant_id : null,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]);

            if ($rating->driver_id) {
                Driver::whereKey($rating->driver_id)->update([
                    'rating' => round((float) DB::table('ratings')->where('driver_id', $rating->driver_id)->avg('rating'), 2),
                ]);
            }

            return $rating->load(['driver.user', 'merchant']);
        }, 3);

        return response()->json([
            'message' => 'Rating submitted successfully.',
            'rating' => $rating,
        ], 201);
    }
}
