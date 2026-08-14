<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantCategory extends Model
{
    use HasFactory;

    protected $table = 'merchant_categories';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function merchants()
    {
        return $this->hasMany(Merchant::class, 'category_id');
    }
}
