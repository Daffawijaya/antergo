<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    public function application(Request $request): JsonResponse
    {
        $driver = Driver::with('vehicle')
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json(['driver' => $driver]);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nik' => ['required', 'digits:16', 'unique:drivers,nik'],
            'license_number' => ['required', 'string', 'max:50', 'unique:drivers,license_number'],
            'vehicle_type' => ['required', 'in:motorcycle,car'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);

        if ($request->user()->driver()->exists()) {
            return response()->json(['message' => 'You already have a driver application.'], 422);
        }

        $driver = DB::transaction(function () use ($request, $validated) {
            $driver = Driver::create([
                'user_id' => $request->user()->id,
                'nik' => $validated['nik'],
                'license_number' => $validated['license_number'],
                'status' => 'pending',
                'is_online' => false,
            ]);
            $driver->vehicle()->create([
                'type' => $validated['vehicle_type'],
                'brand' => $validated['brand'],
                'model' => $validated['model'] ?? null,
                'plate_number' => strtoupper($validated['plate_number']),
                'color' => $validated['color'] ?? null,
            ]);

            return $driver;
        });

        return response()->json([
            'message' => 'Driver application submitted successfully.',
            'driver' => $driver->load('vehicle'),
        ], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $driver = Driver::with([
            'user',
            'vehicle',
            'location',
        ])->where('user_id', $request->user()->id)->first();

        if (! $driver) {
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

        if (! $driver) {
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

        if (! $driver) {
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

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        if ($driver->status !== 'approved') {
            return response()->json([
                'message' => 'Driver is not approved.',
            ], 403);
        }

        if (! $driver->is_online) {
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
