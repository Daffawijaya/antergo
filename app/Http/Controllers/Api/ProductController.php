<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('merchant')
            ->where('is_available', true)
            ->whereHas('merchant', fn ($query) => $query->where('is_active', true));

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->string('product_type')->toString());
        }

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhereHas('merchant', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
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

    public function store(Request $request, SupabaseStorageService $storage): JsonResponse
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
            'product_type' => ['sometimes', 'in:food,goods'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $product = $merchant->products()->create([
            'name' => $validated['name'],
            'product_type' => $validated['product_type'] ?? 'food',
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'image' => null,
            'is_available' => $validated['is_available'] ?? true,
        ]);

        $path = null;
        try {
            $path = $storage->put('product-images', $merchant->id.'/'.$product->id, $request->file('image'));
            $product->setRawAttributes(array_merge($product->getAttributes(), ['image' => $path]));
            $product->save();
        } catch (\Throwable $error) {
            if ($path) {
                $storage->delete('product-images', $path);
            }
            $product->delete();
            throw $error;
        }

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load('merchant'),
        ], 201);
    }

    public function update(
        Request $request,
        Product $product,
        SupabaseStorageService $storage
    ): JsonResponse {
        $merchant = $request->user()->merchant;

        if (! $merchant || $product->merchant_id !== $merchant->id) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'product_type' => ['sometimes', 'in:food,goods'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'image' => ['sometimes', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        $image = $validated['image'] ?? null;
        unset($validated['image']);
        $oldPath = $product->getRawOriginal('image');
        $newPath = $image
            ? $storage->put('product-images', $merchant->id.'/'.$product->id, $image)
            : null;

        try {
            DB::transaction(function () use ($product, $validated, $newPath) {
                $product->update($validated);

                if ($newPath) {
                    $product->setRawAttributes(array_merge($product->getAttributes(), ['image' => $newPath]));
                    $product->save();
                }
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                $storage->delete('product-images', $newPath);
            }

            throw $error;
        }

        if ($newPath && $oldPath && ! str_starts_with($oldPath, 'http')) {
            $storage->delete('product-images', $oldPath);
        }

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh('merchant'),
        ]);
    }

    public function destroy(
        Request $request,
        Product $product,
        SupabaseStorageService $storage
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
