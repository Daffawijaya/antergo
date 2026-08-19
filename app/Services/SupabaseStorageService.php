<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

class SupabaseStorageService
{
    public const PUBLIC_BUCKETS = [
        'merchant-images',
        'product-images',
        'driver-avatars',
        'customer-avatars',
    ];

    public const PRIVATE_BUCKETS = [
        'driver-documents',
    ];

    public function put(
        string $bucket,
        string $directory,
        UploadedFile $file
    ): string {
        $mimeType = (string) $file->getMimeType();

        if (! in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
        ], true)) {
            throw new RuntimeException(
                'Format gambar harus JPG, JPEG, PNG, WebP, HEIC, atau HEIF.'
            );
        }

        $manager = new ImageManager(new Driver());

        $image = $manager->read(
            $file->getRealPath()
        );

        $maxWidth = 2200;
        $maxHeight = 2200;

        $image->scaleDown(
            width: $maxWidth,
            height: $maxHeight,
        );

        $encoded = $image->toWebp(
            quality: 85
        );

        $path = trim($directory, '/')
            . '/'
            . Str::uuid()
            . '.webp';

        $response = $this->request()
            ->withBody(
                $encoded->toString(),
                'image/webp'
            )
            ->post(
                $this->endpoint(
                    "/storage/v1/object/{$bucket}/{$path}"
                )
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Upload media gagal: '
                    . $response->json(
                        'message',
                        $response->body()
                    )
            );
        }

        return $path;
    }

    public function delete(
        string $bucket,
        ?string $path
    ): void {
        if (! $path) {
            return;
        }

        $response = $this->request()
            ->delete(
                $this->endpoint(
                    "/storage/v1/object/{$bucket}/{$path}"
                )
            );

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(
                'Hapus media gagal: '
                    . $response->json(
                        'message',
                        $response->body()
                    )
            );
        }
    }

    public function publicUrl(
        string $bucket,
        ?string $path
    ): ?string {
        if (! $path) {
            return null;
        }

        return $this->endpoint(
            "/storage/v1/object/public/{$bucket}/{$path}"
        );
    }

    public function signedUrl(
        string $bucket,
        string $path,
        int $expires = 300
    ): string {
        $response = $this->request(10)
            ->post(
                $this->endpoint(
                    "/storage/v1/object/sign/{$bucket}/{$path}"
                ),
                [
                    'expiresIn' => $expires,
                ]
            )
            ->throw();

        $url = $response->json('signedURL')
            ?? $response->json('signedUrl');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                'Signed URL tidak tersedia.'
            );
        }

        // Supabase REST API may return a relative path like
        // "/object/sign/bucket/path?token=..." instead of the full
        // "/storage/v1/object/sign/..." path. Prepend the missing
        // prefix so the final URL points to the correct endpoint.
        if (
            ! str_starts_with($url, 'http')
            && ! str_starts_with($url, '/storage/')
        ) {
            $url = '/storage/v1' . $url;
        }

        return str_starts_with($url, 'http')
            ? $url
            : $this->endpoint($url);
    }

    /**
     * Fetch multiple signed URLs in parallel using Guzzle concurrent requests.
     * Returns an array keyed by the original index.
     */
    public function signedUrls(array $paths, string $bucket = 'driver-documents', int $expires = 300): array
    {
        $key = config('services.supabase_storage.service_key');
        if (! $key) {
            throw new RuntimeException('SUPABASE_SERVICE_ROLE_KEY belum dikonfigurasi.');
        }
        $baseUrl = $this->endpoint("/storage/v1/object/sign/{$bucket}");

        $client = new \GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'apikey' => $key,
                'Authorization' => "Bearer {$key}",
            ],
        ]);

        $promises = [];
        foreach ($paths as $i => $path) {
            $promises[$i] = $client->postAsync('/' . ltrim($path, '/'), [
                'json' => ['expiresIn' => $expires],
            ]);
        }

        $results = [];
        \GuzzleHttp\Utils\each_limit($promises, 5, function ($response) use (&$results, $promises) {
            foreach ($promises as $i => $promise) {
                if ($promise->getState() === \GuzzleHttp\Promise\PromiseInterface::FULFILLED) {
                    try {
                        $body = json_decode((string) $response->getBody(), true);
                        $url = $body['signedURL'] ?? $body['signedUrl'] ?? null;
                        if (is_string($url) && $url !== '') {
                            if (! str_starts_with($url, 'http') && ! str_starts_with($url, '/storage/')) {
                                $url = '/storage/v1' . $url;
                            }
                            $results[$i] = str_starts_with($url, 'http') ? $url : $this->endpoint($url);
                        } else {
                            $results[$i] = null;
                        }
                    } catch (\Throwable) {
                        $results[$i] = null;
                    }
                }
            }
        });

        // Fallback: sequential fetch for any that failed
        foreach ($paths as $i => $path) {
            if (! isset($results[$i])) {
                try {
                    $results[$i] = $this->signedUrl($bucket, $path, $expires);
                } catch (\Throwable) {
                    $results[$i] = null;
                }
            }
        }

        return $results;
    }

    public function ensureBucket(
        string $name,
        bool $public,
        int $limit
    ): void {
        $existingBucketResponse = $this->request()
            ->get(
                $this->endpoint(
                    "/storage/v1/bucket/{$name}"
                )
            );

        if ($existingBucketResponse->successful()) {
            return;
        }

        $this->request()
            ->post(
                $this->endpoint(
                    '/storage/v1/bucket'
                ),
                [
                    'id' => $name,
                    'name' => $name,
                    'public' => $public,
                    'file_size_limit' => $limit,
                    'allowed_mime_types' => [
                        'image/webp',
                    ],
                ]
            )
            ->throw();
    }

    private function request(int $timeout = 30)
    {
        $key = config(
            'services.supabase_storage.service_key'
        );

        if (! $key) {
            throw new RuntimeException(
                'SUPABASE_SERVICE_ROLE_KEY belum dikonfigurasi.'
            );
        }

        return Http::acceptJson()
            ->withHeaders([
                'apikey' => $key,
                'Authorization' => "Bearer {$key}",
            ])
            ->timeout($timeout);
    }

    private function endpoint(
        string $path
    ): string {
        $url = rtrim(
            (string) config(
                'services.supabase_storage.url'
            ),
            '/'
        );

        if (! $url) {
            throw new RuntimeException(
                'SUPABASE_URL belum dikonfigurasi.'
            );
        }

        return $url
            . '/'
            . ltrim($path, '/');
    }
}
