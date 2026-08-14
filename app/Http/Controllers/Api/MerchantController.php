<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantController extends Controller
{
    public function index(): JsonResponse
    {
        $merchants = Merchant::with('category')
            ->where('is_active', true)
            ->where('is_open', true)
            ->latest()
            ->paginate(20);

        return response()->json($merchants);
    }

    public function show(Merchant $merchant): JsonResponse
    {
        if (! $merchant->is_active) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        return response()->json([
            'merchant' => $merchant->load([
                'category',
                'products' => fn ($query) => $query->where('is_available', true),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:merchant_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $existingMerchant = Merchant::where(
            'user_id',
            $request->user()->id
        )->first();

        if ($existingMerchant) {
            return response()->json([
                'message' => 'You already have a merchant.',
                'merchant' => $existingMerchant,
            ], 422);
        }

        $merchant = DB::transaction(function () use ($request, $validated) {
            $merchant = Merchant::create([
                'user_id' => $request->user()->id,
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'is_open' => false,
                'is_active' => true,
            ]);
            $request->user()->addRole('merchant');

            return $merchant;
        });

        return response()->json([
            'message' => 'Merchant created successfully.',
            'merchant' => $merchant->load('category'),
        ], 201);
    }

    public function myMerchant(Request $request): JsonResponse
    {
        $merchant = Merchant::with([
            'category',
            'products',
        ])
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        return response()->json([
            'merchant' => $merchant,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $merchant = Merchant::where(
            'user_id',
            $request->user()->id
        )->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        if (! $merchant->is_active) {
            return response()->json([
                'message' => 'Merchant is inactive.',
            ], 422);
        }

        $merchant->update([
            'is_open' => true,
        ]);

        return response()->json([
            'message' => 'Merchant is now open.',
            'merchant' => $merchant->fresh(),
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $merchant = Merchant::where(
            'user_id',
            $request->user()->id
        )->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        $merchant->update([
            'is_open' => false,
        ]);

        return response()->json([
            'message' => 'Merchant is now closed.',
            'merchant' => $merchant->fresh(),
        ]);
    }
}
