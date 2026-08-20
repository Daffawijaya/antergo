<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitipBeliItem extends Model
{
    use HasFactory;

    protected $table = 'titip_beli_items';

    protected $fillable = [
        'location_id',
        'name',
        'quantity',
        'note',
    ];

    public function location()
    {
        return $this->belongsTo(TitipBeliLocation::class, 'location_id');
    }
}
