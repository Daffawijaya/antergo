<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ExpoPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $orders = Order::query()
            ->with(['user:id,name', 'driver.user:id,name'])
            ->select([
                'id', 'order_number', 'type', 'status',
                'user_id', 'driver_id', 'updated_at',
            ])
            ->whereNotNull('driver_id')
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('driver', fn ($driver) => $driver->where('user_id', $user->id));
            })
            ->withCount(['chatMessages as unread_count' => fn ($query) => $query->where('sender_id', '!=', $user->id)->whereNull('read_at')])
            ->with(['chatMessages' => fn ($query) => $query->latest()->limit(1)->with('sender:id,name')])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return response()->json(['conversations' => $orders]);
    }

    public function index(Request $request, Order $order): JsonResponse
    {
        $this->authorizeParticipant($request, $order);
        $order->chatMessages()->where('sender_id', '!=', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['messages' => $order->chatMessages()->with('sender:id,name,avatar')->oldest()->get()]);
    }

    public function store(Request $request, Order $order, ExpoPushNotificationService $push): JsonResponse
    {
        $this->authorizeParticipant($request, $order);
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $order->chatMessages()->create(['sender_id' => $request->user()->id, 'body' => trim($validated['body'])])->load('sender:id,name,avatar');
        $order->loadMissing(['user', 'driver.user']);
        $recipient = $order->user_id === $request->user()->id ? $order->driver?->user : $order->user;
        if ($recipient) {
            $push->notify($recipient, 'chat_message', $request->user()->name, $message->body, ['type' => 'chat_message', 'order_id' => $order->id, 'order_type' => $order->type, 'route' => $recipient->id === $order->user_id ? 'customer_chat' : 'driver_chat']);
        }

        return response()->json(['message' => $message], 201);
    }

    private function authorizeParticipant(Request $request, Order $order): void
    {
        $order->loadMissing('driver');
        if (! $order->driver_id || ($order->user_id !== $request->user()->id && $order->driver?->user_id !== $request->user()->id)) {
            abort(404, 'Conversation not found.');
        }
    }
}
