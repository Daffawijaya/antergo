<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const TYPE_RIDE = 'ride';

    public const TYPE_SEND = 'send';

    public const TYPE_FOOD = 'food';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SEARCHING_DRIVER = 'searching_driver';

    public const STATUS_DRIVER_ASSIGNED = 'driver_assigned';

    public const STATUS_DRIVER_ARRIVED = 'driver_arrived';

    public const STATUS_MERCHANT_CONFIRMED = 'merchant_confirmed';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

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
