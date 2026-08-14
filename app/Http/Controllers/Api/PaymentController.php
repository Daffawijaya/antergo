<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Services\OrderPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function settleCash(Request $request, Order $order): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (! $driver || $order->driver_id !== $driver->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $result = DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['order' => ['Only a completed order can be settled.']]);
            }
            if ($lockedOrder->payment_method !== 'cash') {
                throw ValidationException::withMessages(['payment_method' => ['Only cash payment is supported.']]);
            }

            $payment = $lockedOrder->payment()->lockForUpdate()->firstOrCreate(
                ['order_id' => $lockedOrder->id],
                ['method' => 'cash', 'status' => 'pending', 'amount' => $lockedOrder->total_price],
            );
            $newlyPaid = $payment->status !== 'paid';

            if ($newlyPaid) {
                $payment->update([
                    'method' => 'cash',
                    'status' => 'paid',
                    'amount' => $lockedOrder->total_price,
                    'paid_at' => now(),
                ]);
            }
            if ($lockedOrder->payment_status !== 'paid') {
                $lockedOrder->update(['payment_status' => 'paid']);
            }

            return ['order' => $lockedOrder->fresh(['payment']), 'newly_paid' => $newlyPaid];
        }, 3);

        if ($result['newly_paid']) {
            app(OrderPushNotificationService::class)->cashPaymentSettled($result['order']);
        }

        return response()->json([
            'message' => 'Cash payment settled successfully.',
            'order' => $result['order'],
        ]);
    }
}
