<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'image_path',
    ];

    protected $hidden = [
        'image_path',
    ];

    protected $appends = [
        'image_uploaded',
    ];

    protected function imageUploaded(): Attribute
    {
        return Attribute::get(
            fn (): bool => filled($this->getRawOriginal('image_path'))
        );
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
