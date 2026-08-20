<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JastipItem extends Model
{
    use HasFactory;

    protected $table = 'titip_beli_items';

    protected $fillable = [
        'location_id',
        'name',
        'quantity',
        'unit',
        'price',
        'note',
    ];

    public function location()
    {
        return $this->belongsTo(JastipLocation::class, 'location_id');
    }
}
