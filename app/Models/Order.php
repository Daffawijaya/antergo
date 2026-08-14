<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'order_number',
        'user_id',
        'driver_id',
        'merchant_id',
        'type',
        'pickup_address',
        'pickup_latitude',
        'pickup_longitude',
        'destination_address',
        'destination_latitude',
        'destination_longitude',
        'distance',
        'estimated_duration',
        'subtotal',
        'delivery_fee',
        'service_fee',
        'total_price',
        'payment_method',
        'payment_status',
        'status',
        'notes',
        'cancelled_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_latitude' => 'decimal:7',
            'pickup_longitude' => 'decimal:7',
            'destination_latitude' => 'decimal:7',
            'destination_longitude' => 'decimal:7',
            'distance' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_price' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function rating()
    {
        return $this->hasOne(Rating::class);
    }
}
