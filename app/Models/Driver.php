<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $table = 'drivers';

    protected $fillable = [
        'user_id', 'nik', 'license_number', 'status', 'is_online',
        'rating', 'total_completed_orders', 'active_vehicle_id',
    ];

    protected function casts(): array
    {
        return ['is_online' => 'boolean', 'rating' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'active_vehicle_id');
    }

    public function documents()
    {
        return $this->hasMany(DriverDocument::class);
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
