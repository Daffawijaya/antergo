<?php

namespace App\Services;

use App\Models\Order;

class OrderPricingService
{
    private const BIKE = [5000, 2500, 10000];

    private const CAR = [10000, 4500, 20000];

    private const DELIVERY_MOTORCYCLE = [7000, 2500, 12000];

    private const DELIVERY_CAR = [12000, 4500, 22000];

    public function distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $earthRadius = 6371;
        $latitudeDifference = deg2rad($latitude2 - $latitude1);
        $longitudeDifference = deg2rad($longitude2 - $longitude1);
        $a = sin($latitudeDifference / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($longitudeDifference / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function rideFare(float $distance, string $variant = Order::VARIANT_BIKE): int
    {
        return $this->fare($variant === Order::VARIANT_CAR ? self::CAR : self::BIKE, $distance);
    }

    public function sendFare(float $distance, string $vehicleType = 'motorcycle'): int
    {
        return $this->fare($vehicleType === 'car' ? self::DELIVERY_CAR : self::DELIVERY_MOTORCYCLE, $distance);
    }

    public function rideBreakdown(float $distance, string $variant = Order::VARIANT_BIKE): array
    {
        return $this->breakdown($variant === Order::VARIANT_CAR ? self::CAR : self::BIKE, $distance);
    }

    public function sendBreakdown(float $distance, string $vehicleType = 'motorcycle'): array
    {
        return $this->breakdown($vehicleType === 'car' ? self::DELIVERY_CAR : self::DELIVERY_MOTORCYCLE, $distance);
    }

    private function fare(array $pricing, float $distance): int
    {
        return (int) max($pricing[2], round(($pricing[0] + ($distance * $pricing[1])) / 500) * 500);
    }

    private function breakdown(array $pricing, float $distance): array
    {
        return ['base_fare' => $pricing[0], 'price_per_km' => $pricing[1], 'distance_km' => round($distance, 2), 'total' => $this->fare($pricing, $distance)];
    }
}
