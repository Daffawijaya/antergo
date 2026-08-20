<?php

namespace App\Console\Commands;

use App\Services\SupabaseStorageService;
use Illuminate\Console\Command;

class EnsureSupabaseStorageBuckets extends Command
{
    protected $signature = 'antergo:ensure-storage-buckets';

    protected $description = 'Create required anterGo Supabase Storage buckets when absent';

    public function handle(SupabaseStorageService $storage): int
    {
        foreach (SupabaseStorageService::PUBLIC_BUCKETS as $bucket) {
            $storage->ensureBucket($bucket, true, 2 * 1024 * 1024);
            $this->info("Ready: {$bucket} (public)");
        } foreach (SupabaseStorageService::PRIVATE_BUCKETS as $bucket) {
            $storage->ensureBucket($bucket, false, 3 * 1024 * 1024);
            $this->info("Ready: {$bucket} (private)");
        }

        return self::SUCCESS;
    }
}
