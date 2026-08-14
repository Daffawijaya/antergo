<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverLocation extends Model
{
    use HasFactory;

    protected $table = 'driver_locations';

    public $timestamps = false;

    protected $fillable = [
        'driver_id',
        'latitude',
        'longitude',
        'heading',
        'speed',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'heading' => 'decimal:2',
            'speed' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
