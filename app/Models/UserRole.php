<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    public const CUSTOMER = 'customer';

    public const DRIVER = 'driver';

    public const MERCHANT = 'merchant';

    public const ADMIN = 'admin';

    public const ALLOWED = [self::CUSTOMER, self::DRIVER, self::MERCHANT, self::ADMIN];

    protected $fillable = ['role'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
