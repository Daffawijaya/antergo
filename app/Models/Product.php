<?php

namespace App\Models;

use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = ['merchant_id', 'product_type', 'name', 'description', 'price', 'stock', 'image', 'is_available'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'stock' => 'integer', 'is_available' => 'boolean'];
    }

    protected function image(): Attribute
    {
        return Attribute::get(fn (?string $v) => $this->mediaUrl($v));
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image);
    }

    private function mediaUrl(?string $v): ?string
    {
        if (! $v || str_starts_with($v, 'http') || ! config('services.supabase_storage.url')) {
            return $v;
        }

return app(SupabaseStorageService::class)->publicUrl('product-images', $v);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
