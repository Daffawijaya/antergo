<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => $validated['password'],
                'is_active' => true,
            ]);
            $user->roles()->create(['role' => UserRole::CUSTOMER]);

            return $user;
        });

        $token = $user->createToken('antergo')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => $this->userPayload($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('antergo')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roleNames(),
            // Each role keeps its own photo: users.avatar is the customer
            // photo, drivers.avatar the driver photo, and the merchant logo is
            // the merchant photo. Clients pick by the active role.
            'avatar' => $user->avatar,
            'driver_avatar' => $user->driver?->avatar,
            'merchant_avatar' => $user->merchant?->image_url,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    public function updateAvatar(Request $request, SupabaseStorageService $storage): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048']]);

        $user = $request->user();
        $oldPath = $user->getRawOriginal('avatar');

        // Any format from the client is fine — the storage service always
        // converts uploads to WebP (scaled down) so the file stays small.
        $newPath = $storage->put('driver-avatars', 'users/'.$user->id, $request->file('avatar'));

        try {
            $user->setRawAttributes(array_merge($user->getAttributes(), ['avatar' => $newPath]));
            $user->save();
        } catch (\Throwable $error) {
            $storage->delete('driver-avatars', $newPath);
            throw $error;
        }

        if ($oldPath && ! str_starts_with($oldPath, 'http')) {
            $storage->delete('driver-avatars', $oldPath);
        }

        return response()->json([
            'message' => 'Avatar updated successfully',
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }
}
