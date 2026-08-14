<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushNotificationController extends Controller
{
    public function registerToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ]);

        $device = PushDeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            ['user_id' => $request->user()->id, 'platform' => $validated['platform'], 'last_seen_at' => now()],
        );

        return response()->json(['message' => 'Push token registered.', 'device_token_id' => $device->id]);
    }

    public function unregisterToken(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:500']]);
        PushDeviceToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Push token unregistered.']);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->notifications()->latest()->paginate(20)
        );
    }
}
