<?php

namespace App\Models;

use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    use HasFactory;

    protected $table = 'merchants';

    protected $fillable = ['user_id', 'category_id', 'name', 'description', 'phone', 'address', 'latitude', 'longitude', 'logo', 'is_open', 'is_active'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_open' => 'boolean', 'is_active' => 'boolean'];
    }

    protected function logo(): Attribute
    {
        return Attribute::get(fn (?string $v) => $this->mediaUrl($v));
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo);
    }

    private function mediaUrl(?string $v): ?string
    {
        if (! $v || str_starts_with($v, 'http') || ! config('services.supabase_storage.url')) {
            return $v;
        }

return app(SupabaseStorageService::class)->publicUrl('merchant-images', $v);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(MerchantCategory::class, 'category_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }
}
