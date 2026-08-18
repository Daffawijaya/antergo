<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAnterGoSchema;
use Tests\TestCase;

class DriverDocumentTest extends TestCase
{
    use BuildsAnterGoSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAnterGoSchema();
        config([
            'services.supabase_storage.url' => 'https://storage.test',
            'services.supabase_storage.service_key' => 'key',
        ]);
        Http::fake(fn ($request) => str_contains($request->url(), '/object/sign/')
            ? Http::response(['signedURL' => 'https://storage.test/signed/abc'], 200)
            : Http::response(['Key' => 'stored'], 200));
    }

    public function test_driver_can_upload_document_with_expiry_date(): void
    {
        $user = $this->driver();
        Sanctum::actingAs($user);

        $this->post('/api/driver/documents', [
            'type' => 'ktp',
            'photo' => $this->image('ktp.png'),
            'expires_at' => '2030-12-31',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.documents.0.type', 'ktp')
            ->assertJsonPath('driver.documents.0.uploaded', true)
            ->assertJsonPath('driver.documents.0.expires_at', '2030-12-31');

        $doc = $user->driver->documents()->where('type', 'ktp')->first();
        $this->assertNotNull($doc);
        $this->assertSame('2030-12-31', $doc->expires_at?->toDateString());
    }

    public function test_driver_can_update_expiry_date_without_reuploading_photo(): void
    {
        $user = $this->driver();
        $user->driver->documents()->create([
            'type' => 'ktp',
            'file_path' => '1/ktp/old-uuid.webp',
            'expires_at' => '2029-01-01',
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/driver/documents', [
            'type' => 'ktp',
            'expires_at' => '2031-05-05',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.documents.0.expires_at', '2031-05-05');

        $doc = $user->driver->documents()->where('type', 'ktp')->first();
        $this->assertNotNull($doc);
        $this->assertSame('1/ktp/old-uuid.webp', $doc->getRawOriginal('file_path'));
        $this->assertSame('2031-05-05', $doc->expires_at?->toDateString());
    }

    public function test_reuploading_photo_replaces_file_and_deletes_previous(): void
    {
        $user = $this->driver();
        $user->driver->documents()->create([
            'type' => 'sim_a',
            'file_path' => '1/sim-a/old-uuid.webp',
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/driver/documents', [
            'type' => 'sim_a',
            'photo' => $this->image('sim-a.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.documents.0.expires_at', null);

        Http::assertSent(
            fn ($request) => $request->method() === 'DELETE'
                && str_contains($request->url(), 'old-uuid.webp')
        );
    }

    public function test_document_without_photo_is_rejected_when_none_exists(): void
    {
        $user = $this->driver();
        Sanctum::actingAs($user);

        $this->post('/api/driver/documents', [
            'type' => 'sim_c',
            'expires_at' => '2030-12-31',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_driver_can_list_documents_with_signed_photo_urls(): void
    {
        $user = $this->driver();
        $user->driver->documents()->create([
            'type' => 'sim_a',
            'file_path' => '1/sim-a/x.webp',
            'expires_at' => '2031-01-01',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/driver/documents')
            ->assertOk()
            ->assertJsonPath('documents.0.type', 'sim_a')
            ->assertJsonPath('documents.0.uploaded', true)
            ->assertJsonPath('documents.0.expires_at', '2031-01-01')
            ->assertJsonPath('documents.0.photo_url', 'https://storage.test/signed/abc');
    }

    public function test_driver_can_delete_document_and_removes_file(): void
    {
        $user = $this->driver();
        $user->driver->documents()->create([
            'type' => 'ktp',
            'file_path' => '1/ktp/delete-me.webp',
        ]);
        Sanctum::actingAs($user);

        $this->delete('/api/driver/documents/ktp', [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.documents', []);

        $this->assertDatabaseMissing('driver_documents', [
            'driver_id' => $user->driver->id,
            'type' => 'ktp',
        ]);
        Http::assertSent(
            fn ($request) => $request->method() === 'DELETE'
                && str_contains($request->url(), 'delete-me.webp')
        );
    }

    public function test_delete_invalid_document_type_is_rejected(): void
    {
        $user = $this->driver();
        Sanctum::actingAs($user);

        $this->delete('/api/driver/documents/paspor', [], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_document_type_must_be_valid(): void
    {
        $user = $this->driver();
        Sanctum::actingAs($user);

        $this->post('/api/driver/documents', [
            'type' => 'paspor',
            'photo' => $this->image('paspor.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    private function driver(): User
    {
        $user = User::create([
            'name' => 'Driver',
            'email' => 'documents@example.com',
            'phone' => '081234567891',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->roles()->create(['role' => UserRole::DRIVER]);
        Driver::create([
            'user_id' => $user->id,
            'nik' => '1234567890123456',
            'license_number' => 'LIC-'.str()->random(8),
            'status' => 'approved',
        ]);

        return $user;
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
