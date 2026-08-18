<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverController extends Controller
{
    public function application(Request $r): JsonResponse
    {
        $d = Driver::with(['vehicles', 'vehicle', 'documents'])->where('user_id', $r->user()->id)->first();

        return response()->json(['driver' => $this->present($d)]);
    }

    public function apply(Request $r, SupabaseStorageService $storage): JsonResponse
    {
        $v = $r->validate(['nik' => ['required', 'digits:16', 'unique:drivers,nik'], 'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'], 'ktp' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'], 'sim_a' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'], 'sim_c' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'], 'vehicles' => ['required', 'array', 'min:1'], 'vehicles.*.type' => ['required', 'in:motorcycle,car'], 'vehicles.*.brand' => ['required', 'string', 'max:100'], 'vehicles.*.model' => ['required', 'string', 'max:100'], 'vehicles.*.plate_number' => ['required', 'string', 'max:20', 'distinct', 'unique:vehicles,plate_number'], 'vehicles.*.color' => ['required', 'string', 'max:50'], 'vehicle_images' => ['required', 'array'], 'vehicle_images.*' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048']]);
        if ($r->user()->driver()->exists()) {
            throw ValidationException::withMessages(['driver' => ['You already have a driver application.']]);
        }
        $this->validateLicenses($v['vehicles'], $r->hasFile('sim_a'), $r->hasFile('sim_c'));
        $uploaded = [];
        try {
            $driver = DB::transaction(function () use ($r, $v, $storage, &$uploaded) {
                $d = Driver::create(['user_id' => $r->user()->id, 'nik' => $v['nik'], 'license_number' => 'PENDING-'.str()->random(20), 'status' => 'pending', 'is_online' => false]);
                
                $photo = $storage->put('driver-avatars', (string) $d->id, $r->file('photo'));
                $uploaded[] = ['driver-avatars', $photo];
                $d->update(['photo_url' => $photo]);
                foreach (['ktp', 'sim_a', 'sim_c'] as $type) {
                    if (! $r->hasFile($type)) {
                        continue;
                    }
                    $path = $storage->put('driver-documents', $d->id.'/'.str_replace('_', '-', $type), $r->file($type));
                    $uploaded[] = ['driver-documents', $path];
                    $d->documents()->create(['type' => $type, 'file_path' => $path]);
                } foreach ($v['vehicles'] as $i => $data) {
                    $vehicle = $d->vehicles()->create(['type' => $data['type'], 'brand' => $data['brand'], 'model' => $data['model'], 'plate_number' => strtoupper($data['plate_number']), 'color' => $data['color']]);
                    $path = $storage->put('driver-documents', 'vehicles/'.$d->id.'/'.$vehicle->id, $r->file("vehicle_images.$i"));
                    $uploaded[] = ['driver-documents', $path];
                    $vehicle->update(['image_path' => $path]);
                    if (! $d->active_vehicle_id) {
                        $d->update(['active_vehicle_id' => $vehicle->id]);
                    }
                }

                return $d;
            });
        } catch (\Throwable $e) {
            foreach ($uploaded as [$bucket,$path]) {
                $storage->delete($bucket, $path);
            }throw $e;
        }

        return response()->json(['message' => 'Driver application submitted successfully.', 'driver' => $this->present($driver->load(['user', 'vehicles', 'vehicle', 'documents']))], 201);
    }

    public function profile(Request $r): JsonResponse
    {
        $d = Driver::with(['user', 'vehicles', 'vehicle', 'documents', 'location'])->where('user_id', $r->user()->id)->first();
        if (! $d) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        return response()->json(['driver' => $this->present($d)]);
    }

    public function addVehicle(Request $r, SupabaseStorageService $storage): JsonResponse
    {
        $d = $this->driver($r);
        $v = $r->validate(['type' => ['required', 'in:motorcycle,car'], 'brand' => ['required', 'string', 'max:100'], 'model' => ['required', 'string', 'max:100'], 'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'], 'color' => ['required', 'string', 'max:50'], 'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'], 'sim' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072']]);
        $docType = $v['type'] === 'car' ? 'sim_a' : 'sim_c';
        $existingDoc = $d->documents()->where('type', $docType)->first();
        // Block if driver has no SIM at all for this vehicle type.
        if (! $existingDoc) {
            throw ValidationException::withMessages(['type' => ['Anda belum memiliki '.strtoupper(str_replace('_', ' ', $docType)).'. Tambahkan SIM terlebih dahulu di Dokumen & SIM.']]);
        }
        // Allow SIM renewal only if the existing SIM is expired.
        if ($existingDoc->expires_at?->isPast() && ! $r->hasFile('sim')) {
            throw ValidationException::withMessages(['sim' => [strtoupper(str_replace('_', ' ', $docType)).' sudah kedaluwarsa ('.$existingDoc->expires_at->toDateString().'). Perbarui SIM Anda di Dokumen & SIM.']]);
        }

        $uploaded = [];
        $vehicle = null;
        try {
            $vehicle = $d->vehicles()->create(['type' => $v['type'], 'brand' => $v['brand'], 'model' => $v['model'], 'plate_number' => strtoupper($v['plate_number']), 'color' => $v['color']]);
            $path = $storage->put('driver-documents', 'vehicles/'.$d->id.'/'.$vehicle->id, $r->file('image'));
            $uploaded[] = ['driver-documents', $path];
            $vehicle->update(['image_path' => $path]);
            if ($r->hasFile('sim')) {
                $dp = $storage->put('driver-documents', $d->id.'/'.str_replace('_', '-', $docType), $r->file('sim'));
                $uploaded[] = ['driver-documents', $dp];
                // A freshly uploaded SIM has no known expiry yet; it must be
                // set from Dokumen & SIM.
                $d->documents()->updateOrCreate(['type' => $docType], ['file_path' => $dp, 'expires_at' => null]);
            }
            if (! $d->active_vehicle_id) {
                $d->update(['active_vehicle_id' => $vehicle->id]);
            }
        } catch (\Throwable $error) {
            foreach ($uploaded as [$bucket, $uploadedPath]) {
                $storage->delete($bucket, $uploadedPath);
            }
            $vehicle?->delete();

            throw $error;
        }

        return response()->json(['vehicle' => $vehicle, 'driver' => $this->present($d->fresh(['vehicles', 'vehicle', 'documents']))], 201);
    }

    public function updateDocument(Request $r, SupabaseStorageService $storage): JsonResponse
    {
        $d = $this->driver($r);

        $v = $r->validate([
            'type' => ['required', 'in:ktp,sim_a,sim_c'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $doc = $d->documents()->where('type', $v['type'])->first();
        $oldPath = $doc?->getRawOriginal('file_path');

        if (! $r->hasFile('photo') && ! $doc) {
            throw ValidationException::withMessages(['photo' => ['Upload foto dokumen terlebih dahulu.']]);
        }

        $newPath = $r->hasFile('photo')
            ? $storage->put('driver-documents', $d->id.'/'.str_replace('_', '-', $v['type']), $r->file('photo'))
            : null;

        try {
            $doc = $d->documents()->updateOrCreate(
                ['type' => $v['type']],
                [
                    'file_path' => $newPath ?? $oldPath,
                    'expires_at' => $v['expires_at'] ?? $doc?->expires_at?->toDateString(),
                ]
            );
        } catch (\Throwable $error) {
            if ($newPath) {
                $storage->delete('driver-documents', $newPath);
            }
            throw $error;
        }

        if ($oldPath && $newPath && $oldPath !== $newPath) {
            $storage->delete('driver-documents', $oldPath);
        }

        return response()->json([
            'message' => 'Dokumen driver diperbarui.',
            'driver' => $this->present($d->fresh(['user', 'vehicles', 'vehicle', 'documents'])),
        ]);
    }

    public function documents(Request $r, SupabaseStorageService $storage): JsonResponse
    {
        $d = $this->driver($r);

        $documents = $d->documents()->get()->map(function ($doc) use ($storage) {
            try {
                $photoUrl = $storage->signedUrl('driver-documents', $doc->getRawOriginal('file_path'));
            } catch (\Throwable) {
                // A failed signed URL should not fail the whole list; the
                // frontend still shows status and expiry without the preview.
                $photoUrl = null;
            }

            return [
                'type' => $doc->type,
                'uploaded' => true,
                'expires_at' => $doc->expires_at?->toDateString(),
                'photo_url' => $photoUrl,
            ];
        })->values();

        return response()->json(['documents' => $documents]);
    }

    public function destroyDocument(Request $r, SupabaseStorageService $storage, string $type): JsonResponse
    {
        $d = $this->driver($r);

        if (! in_array($type, ['ktp', 'sim_a', 'sim_c'], true)) {
            return response()->json([
                'message' => 'Jenis dokumen tidak valid.',
            ], 422);
        }

        $doc = $d->documents()->where('type', $type)->first();

        if (! $doc) {
            return response()->json([
                'message' => 'Dokumen tidak ditemukan.',
            ], 404);
        }

        $path = $doc->getRawOriginal('file_path');
        $doc->delete();
        $storage->delete('driver-documents', $path);

        return response()->json([
            'message' => 'Dokumen dihapus.',
            'driver' => $this->present($d->fresh(['user', 'vehicles', 'vehicle', 'documents'])),
        ]);
    }

    public function setActiveVehicle(Request $r, Vehicle $vehicle): JsonResponse
    {
        $d = $this->driver($r);
        abort_unless($vehicle->driver_id === $d->id, 404);
        $this->assertVehicleSimValid($d, $vehicle);
        $d->update(['active_vehicle_id' => $vehicle->id]);

        return response()->json(['driver' => $this->present($d->fresh(['vehicles', 'vehicle', 'documents']))]);
    }

    public function online(Request $r): JsonResponse
    {
        $d = $this->driver($r);
        if ($d->status !== 'approved') {
            return response()->json(['message' => 'Driver is not approved.'], 403);
        }
        if (! $d->active_vehicle_id) {
            $vehicles = $d->vehicles()->get();
            if ($vehicles->count() === 1) {
                $d->update(['active_vehicle_id' => $vehicles->first()->id]);
            } else {
                return response()->json(['message' => 'Pilih kendaraan aktif terlebih dahulu.'], 422);
            }
        }
        $vehicle = $d->vehicles()->whereKey($d->active_vehicle_id)->first();
        if ($vehicle) {
            $this->assertVehicleSimValid($d, $vehicle);
        }
        $d->update(['is_online' => true]);

        return response()->json(['message' => 'Driver is now online.', 'driver' => $this->present($d->fresh(['vehicles', 'vehicle', 'documents']))]);
    }

    public function offline(Request $r): JsonResponse
    {
        $d = $this->driver($r);
        $d->update(['is_online' => false]);

        return response()->json(['message' => 'Driver is now offline.', 'driver' => $this->present($d->fresh(['vehicles', 'vehicle', 'documents']))]);
    }

    public function updateLocation(Request $r): JsonResponse
    {
        $v = $r->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'heading' => ['nullable', 'numeric', 'between:0,360'], 'speed' => ['nullable', 'numeric', 'min:0']]);
        $d = $this->driver($r);
        if ($d->status !== 'approved' || ! $d->is_online) {
            return response()->json(['message' => 'Driver is offline or not approved.'], 403);
        }
        $l = $d->location()->updateOrCreate(['driver_id' => $d->id], $v + ['updated_at' => now()]);

        return response()->json(['message' => 'Driver location updated.', 'location' => $l]);
    }

    private function driver(Request $r): Driver
    {
        $d = Driver::where('user_id', $r->user()->id)->first();
        if (! $d) {
            abort(404, 'Driver profile not found.');
        }

        return $d;
    }

    private function assertVehicleSimValid(Driver $d, Vehicle $vehicle): void
    {
        $docType = $vehicle->type === 'car' ? 'sim_a' : 'sim_c';
        $doc = $d->documents()->where('type', $docType)->first();

        if ($doc && $doc->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'sim' => [strtoupper(str_replace('_', ' ', $docType)).' sudah kedaluwarsa ('.$doc->expires_at->toDateString().'). Perbarui SIM Anda di Dokumen & SIM.'],
            ]);
        }
    }

    private function validateLicenses(array $vehicles, bool $a, bool $c): void
    {
        $types = collect($vehicles)->pluck('type');
        $errors = [];
        if ($types->contains('car') && ! $a) {
            $errors['sim_a'] = ['SIM A wajib untuk kendaraan mobil.'];
        }
        if ($types->contains('motorcycle') && ! $c) {
            $errors['sim_c'] = ['SIM C wajib untuk kendaraan motor.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function present(?Driver $d): ?Driver
    {
        if (! $d) {
            return null;
        }
        $docs = $d->documents ?? collect();
        $types = $docs->pluck('type');
        $d->setRelation('documents', $docs->map(fn ($doc) => [
            'type' => $doc->type,
            'uploaded' => true,
            'expires_at' => $doc->expires_at?->toDateString(),
        ])->values());
        $d->setAttribute('document_profile_complete', $d->getRawOriginal('photo_url') && $types->contains('ktp') && $d->vehicles?->isNotEmpty() && $d->vehicles->every(fn ($v) => $v->image_path && $types->contains($v->type === 'car' ? 'sim_a' : 'sim_c')));

        return $d;
    }
}
