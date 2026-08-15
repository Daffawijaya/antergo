<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchant_categories')) {
            return;
        }

        $categories = [
            'food' => 'Makanan & Minuman',
            'fashion' => 'Fashion',
            'electronics' => 'Elektronik & Gadget',
            'beauty' => 'Kecantikan & Perawatan',
            'health' => 'Kesehatan',
            'home-living' => 'Rumah Tangga',
            'mother-baby' => 'Ibu & Anak',
            'books-stationery' => 'Buku & Alat Tulis',
            'hobby-sports' => 'Hobi & Olahraga',
            'automotive' => 'Otomotif',
            'agriculture' => 'Pertanian & Peternakan',
            'crafts-souvenirs' => 'Kerajinan & Oleh-oleh',
        ];

        foreach ($categories as $slug => $name) {
            DB::table('merchant_categories')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }

    public function down(): void
    {
        // Retain categories so existing merchant references are never orphaned.
    }
};
