<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $driver = Driver::with([
            'user',
            'vehicle',
            'location',
        ])->where('user_id', $request->user()->id)->first();

        if (!$driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        return response()->json([
            'driver' => $driver,
        ]);
    }

    public function online(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (!$driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        if ($driver->status !== 'approved') {
            return response()->json([
                'message' => 'Driver is not approved.',
            ], 403);
        }

        $driver->update([
            'is_online' => true,
        ]);

        return response()->json([
            'message' => 'Driver is now online.',
            'driver' => $driver->fresh(),
        ]);
    }

    public function offline(Request $request): JsonResponse
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (!$driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $driver->update([
            'is_online' => false,
        ]);

        return response()->json([
            'message' => 'Driver is now offline.',
            'driver' => $driver->fresh(),
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0'],
        ]);

        $driver = Driver::where('user_id', $request->user()->id)->first();

        if (!$driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        if ($driver->status !== 'approved') {
            return response()->json([
                'message' => 'Driver is not approved.',
            ], 403);
        }

        if (!$driver->is_online) {
            return response()->json([
                'message' => 'Driver is offline.',
            ], 403);
        }

        $location = $driver->location()->updateOrCreate(
            ['driver_id' => $driver->id],
            [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'heading' => $validated['heading'] ?? null,
                'speed' => $validated['speed'] ?? null,
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Driver location updated.',
            'location' => $location,
        ]);
    }
}
