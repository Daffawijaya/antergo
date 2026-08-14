<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $table = 'drivers';

    protected $fillable = [
        'user_id',
        'nik',
        'license_number',
        'status',
        'is_online',
        'rating',
        'total_completed_orders',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'rating' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->hasOne(Vehicle::class);
    }

    public function location()
    {
        return $this->hasOne(DriverLocation::class);
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
