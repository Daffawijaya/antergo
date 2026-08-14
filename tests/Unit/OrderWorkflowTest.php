<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FoodOrderController;
use App\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OrderWorkflowTest extends TestCase
{
    #[DataProvider('databaseStatusProvider')]
    public function test_order_status_constants_match_database_constraints(string $status): void
    {
        $this->assertContains($status, [
            'pending',
            'searching_driver',
            'driver_assigned',
            'driver_arrived',
            'merchant_confirmed',
            'preparing',
            'ready_for_pickup',
            'picked_up',
            'in_progress',
            'delivering',
            'completed',
            'cancelled',
        ]);
    }

    public static function databaseStatusProvider(): array
    {
        return [
            [Order::STATUS_PENDING],
            [Order::STATUS_SEARCHING_DRIVER],
            [Order::STATUS_DRIVER_ASSIGNED],
            [Order::STATUS_DRIVER_ARRIVED],
            [Order::STATUS_MERCHANT_CONFIRMED],
            [Order::STATUS_PREPARING],
            [Order::STATUS_READY_FOR_PICKUP],
            [Order::STATUS_PICKED_UP],
            [Order::STATUS_IN_PROGRESS],
            [Order::STATUS_DELIVERING],
            [Order::STATUS_COMPLETED],
            [Order::STATUS_CANCELLED],
        ];
    }

    public function test_delivery_fee_is_calculated_server_side_with_minimum_and_rounding(): void
    {
        $method = new ReflectionMethod(FoodOrderController::class, 'calculateDeliveryFee');
        $controller = new FoodOrderController;

        $this->assertSame(8000, $method->invoke($controller, 0.0));
        $this->assertSame(10000, $method->invoke($controller, 2.0));
        $this->assertSame(11500, $method->invoke($controller, 2.5));
    }
}
