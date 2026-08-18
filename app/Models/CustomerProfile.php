<?php

namespace App\Models;

use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    use HasFactory;

    protected $table = 'customer_profiles';

    protected $fillable = ['user_id', 'photo_url'];

    protected function photoUrl(): Attribute
    {
        return Attribute::get(function (?string $value) {
            if (! $value || str_starts_with($value, 'http') || ! config('services.supabase_storage.url')) {
                return $value;
            }

            return app(SupabaseStorageService::class)->publicUrl('customer-avatars', $value);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
