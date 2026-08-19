<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => MerchantCategory::query()
                ->whereNotIn('slug', ['jasa', 'service', 'services'])
                ->whereRaw('LOWER(name) <> ?', ['jasa'])
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $merchants = Merchant::with('category')
            ->when($request->filled('product_type'), fn ($query) => $query->whereHas('products', fn ($products) => $products->where('product_type', $request->string('product_type')->toString())->where('is_available', true)))
            ->where('is_active', true)
            ->where('is_open', true)
            ->latest()
            ->paginate(20);

        return response()->json($merchants);
    }

    public function show(Request $request, Merchant $merchant): JsonResponse
    {
        if (! $merchant->is_active) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        return response()->json([
            'merchant' => $merchant->load([
                'category',
                'products' => fn ($query) => $query->where('is_available', true)->when($request->filled('product_type'), fn ($products) => $products->where('product_type', $request->string('product_type')->toString())),
            ]),
        ]);
    }

    public function store(Request $request, SupabaseStorageService $storage): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:merchant_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
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

        $uploadedPath = null;
        try {
            $merchant = DB::transaction(function () use ($request, $validated, $storage, &$uploadedPath) {
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
                $uploadedPath = $storage->put('merchant-images', (string) $merchant->id, $request->file('image'));
                $merchant->setRawAttributes(array_merge($merchant->getAttributes(), ['photo_url' => $uploadedPath]));
                $merchant->save();
                $request->user()->addRole('merchant');

                return $merchant;
            });
        } catch (\Throwable $error) {
            if ($uploadedPath) {
                $storage->delete('merchant-images', $uploadedPath);
            }
            throw $error;
        }

        return response()->json([
            'message' => 'Merchant created successfully.',
            'merchant' => $merchant->load('category'),
        ], 201);
    }

    public function updateImage(Request $request, SupabaseStorageService $storage): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048']]);
        $merchant = Merchant::where('user_id', $request->user()->id)->firstOrFail();
        $oldPath = $merchant->getRawOriginal('photo_url');
        $newPath = $storage->put('merchant-images', (string) $merchant->id, $request->file('image'));
        try {
            $merchant->setRawAttributes(array_merge($merchant->getAttributes(), ['photo_url' => $newPath]));
            $merchant->save();
        } catch (\Throwable $error) {
            $storage->delete('merchant-images', $newPath);
            throw $error;
        }
        if ($oldPath && ! str_starts_with($oldPath, 'http')) {
            $storage->delete('merchant-images', $oldPath);
        }

        return response()->json(['merchant' => $merchant->fresh('category')]);
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
    }    public function close(Request $request): JsonResponse
    {
        $merchant = Merchant::where('user_id', $request->user()->id)->first();

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

    public function updateCoverImage(Request $request, SupabaseStorageService $storage): JsonResponse
    {
        $request->validate([
            'cover_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $merchant = Merchant::where('user_id', $request->user()->id)->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        $oldPath = $merchant->getRawOriginal('cover_image');
        $newPath = $storage->put('merchant-images', (string) $merchant->id . '_cover', $request->file('cover_image'));

        try {
            $merchant->setRawAttributes(array_merge($merchant->getAttributes(), ['cover_image' => $newPath]));
            $merchant->save();
        } catch (\Throwable $error) {
            $storage->delete('merchant-images', $newPath);
            throw $error;
        }

        if ($oldPath && ! str_starts_with($oldPath, 'http')) {
            $storage->delete('merchant-images', $oldPath);
        }

        return response()->json(['merchant' => $merchant->fresh('category')]);
    }

    public function destroyCoverImage(SupabaseStorageService $storage): JsonResponse
    {
        $merchant = Merchant::where('user_id', $request()->user()->id)->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Merchant not found.',
            ], 404);
        }

        $oldPath = $merchant->getRawOriginal('cover_image');

        if ($oldPath && ! str_starts_with($oldPath, 'http')) {
            $storage->delete('merchant-images', $oldPath);
        }

        $merchant->setRawAttributes(array_merge($merchant->getAttributes(), ['cover_image' => null]));
        $merchant->save();

        return response()->json(['merchant' => $merchant->fresh('category')]);
    }
}
