<?php

namespace App\Services;

class OrderPricingService
{
    private const RIDE_BASE_FARE = 5000;
    private const RIDE_PRICE_PER_KM = 2500;
    private const RIDE_MINIMUM_FARE = 10000;
    private const SEND_BASE_FARE = 7000;
    private const SEND_PRICE_PER_KM = 2500;
    private const SEND_MINIMUM_FARE = 12000;

    public function distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $earthRadius = 6371;
        $latitudeDifference = deg2rad($latitude2 - $latitude1);
        $longitudeDifference = deg2rad($longitude2 - $longitude1);
        $a = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2))
            * sin($longitudeDifference / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function rideFare(float $distance): int
    {
        return $this->roundedFare(self::RIDE_BASE_FARE, self::RIDE_PRICE_PER_KM, self::RIDE_MINIMUM_FARE, $distance);
    }

    public function sendFare(float $distance): int
    {
        return $this->roundedFare(self::SEND_BASE_FARE, self::SEND_PRICE_PER_KM, self::SEND_MINIMUM_FARE, $distance);
    }

    public function rideBreakdown(float $distance): array
    {
        return [
            'base_fare' => self::RIDE_BASE_FARE,
            'price_per_km' => self::RIDE_PRICE_PER_KM,
            'distance_km' => round($distance, 2),
            'total' => $this->rideFare($distance),
        ];
    }
    public function sendBreakdown(float $distance): array
    {
        return [
            'base_fare' => self::SEND_BASE_FARE,
            'price_per_km' => self::SEND_PRICE_PER_KM,
            'distance_km' => round($distance, 2),
            'total' => $this->sendFare($distance),
        ];
    }

    private function roundedFare(int $base, int $perKm, int $minimum, float $distance): int
    {
        return (int) max($minimum, round(($base + ($distance * $perKm)) / 500) * 500);
    }
}
