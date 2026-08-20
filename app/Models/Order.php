<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const TYPE_RIDE = 'ride';

    public const TYPE_SEND = 'send';

    public const TYPE_FOOD = 'food';

    public const TYPE_JASTIP = 'jastip';

    public const VARIANT_BIKE = 'bike';

    public const VARIANT_CAR = 'car';

    public const VARIANT_DELIVERY = 'delivery';

    public const VARIANT_FOOD = 'food';

    public const VARIANT_SHOPPING = 'shopping';

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

    protected $appends = ['send_details'];

    protected $fillable = [
        'order_number',
        'user_id',
        'driver_id',
        'vehicle_id',
        'vehicle_snapshot',
        'merchant_id',
        'type',
        'service_variant',
        'vehicle_type',
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
        'advance_amount',
        'driver_note',
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
            'advance_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'vehicle_snapshot' => 'array',
        ];
    }

    protected function notes(): Attribute
    {
        return Attribute::get(function (?string $value) {
            if ($this->type !== self::TYPE_SEND || $value === null) {
                return $value;
            }

            $details = json_decode($value, true);

            return is_array($details) ? ($details['notes'] ?? null) : $value;
        });
    }

    protected function sendDetails(): Attribute
    {
        return Attribute::get(function () {
            if ($this->type !== self::TYPE_SEND) {
                return null;
            }

            $details = json_decode($this->attributes['notes'] ?? '', true);

            return is_array($details) ? [
                'item_name' => $details['item_name'] ?? null,
                'item_description' => $details['item_description'] ?? null,
                'recipient_name' => $details['recipient_name'] ?? null,
                'recipient_phone' => $details['recipient_phone'] ?? null,
            ] : null;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
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

    public function jastipLocations()
    {
        return $this->hasMany(JastipLocation::class);
    }
}
