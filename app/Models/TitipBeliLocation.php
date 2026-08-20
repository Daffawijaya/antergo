<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitipBeliLocation extends Model
{
    use HasFactory;

    protected $table = 'titip_beli_locations';

    protected $fillable = [
        'order_id',
        'sequence',
        'place_name',
        'address',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(TitipBeliItem::class, 'location_id');
    }
}
