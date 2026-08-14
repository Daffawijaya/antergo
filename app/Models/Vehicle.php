<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $table = 'vehicles';

    protected $fillable = [
        'driver_id',
        'type',
        'brand',
        'model',
        'plate_number',
        'color',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
