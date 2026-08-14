<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('merchant')
            ->where('is_available', true)
            ->whereHas('merchant', fn ($query) => $query->where('is_active', true));

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate(20)
        );
    }

    public function show(Product $product): JsonResponse
    {
        if (! $product->is_available || ! $product->merchant?->is_active) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'product' => $product->load('merchant'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $request->user()
            ->merchant;

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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $product = $merchant->products()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'image' => $validated['image'] ?? null,
            'is_available' => $validated['is_available'] ?? true,
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load('merchant'),
        ], 201);
    }

    public function update(
        Request $request,
        Product $product
    ): JsonResponse {
        $merchant = $request->user()->merchant;

        if (! $merchant || $product->merchant_id !== $merchant->id) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh('merchant'),
        ]);
    }

    public function destroy(
        Request $request,
        Product $product
    ): JsonResponse {
        $merchant = $request->user()->merchant;

        if (! $merchant || $product->merchant_id !== $merchant->id) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        // order_items keeps a restricted FK to products so historical snapshots
        // remain valid. Removing a catalog item therefore means deactivating it.
        $product->update(['is_available' => false]);

        return response()->json([
            'message' => 'Product removed from the catalog successfully.',
        ]);
    }
}
