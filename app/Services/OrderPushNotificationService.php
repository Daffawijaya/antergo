<?php

namespace App\Services;

use App\Models\Order;

class OrderPushNotificationService
{
    public function __construct(private readonly ExpoPushNotificationService $push)
    {
    }

    public function foodCreated(Order $order): void
    {
        $order->loadMissing('merchant.user');
        if ($order->merchant?->user) {
            $this->send($order->merchant->user, 'food_order_created', 'Pesanan Food baru', "Pesanan {$order->order_number} menunggu konfirmasi.", 'merchant_food_detail', $order);
        }
    }

    public function merchantStatusChanged(Order $order): void
    {
        $order->loadMissing('user');
        $copy = match ($order->status) {
            Order::STATUS_MERCHANT_CONFIRMED => ['Pesanan dikonfirmasi', 'Merchant telah menerima pesanan Food Anda.'],
            Order::STATUS_PREPARING => ['Pesanan sedang disiapkan', 'Merchant sedang menyiapkan pesanan Food Anda.'],
            Order::STATUS_READY_FOR_PICKUP => ['Pesanan siap diambil', 'Pesanan Food Anda siap diambil oleh driver.'],
            default => null,
        };
        if ($copy) {
            $this->send($order->user, "food_{$order->status}", $copy[0], $copy[1], 'customer_food_detail', $order);
        }
    }

    public function driverAssigned(Order $order): void
    {
        $order->loadMissing(['user', 'merchant.user']);
        $this->send($order->user, "{$order->type}_driver_assigned", 'Driver ditemukan', "Driver telah menerima order {$order->order_number}.", $this->customerRoute($order), $order);

        if ($order->type === Order::TYPE_FOOD && $order->merchant?->user) {
            $this->send($order->merchant->user, 'food_driver_assigned', 'Driver menuju merchant', "Driver telah ditugaskan untuk {$order->order_number}.", 'merchant_food_detail', $order);
        }
    }

    public function driverStatusChanged(Order $order): void
    {
        $order->loadMissing(['user', 'merchant.user']);
        $copy = match ($order->type) {
            Order::TYPE_FOOD => match ($order->status) {
                Order::STATUS_PICKED_UP => ['Pesanan sudah diambil', 'Driver telah mengambil pesanan dari merchant.'],
                Order::STATUS_DELIVERING => ['Pesanan sedang diantar', 'Driver sedang menuju alamat tujuan.'],
                Order::STATUS_COMPLETED => ['Pesanan telah sampai', 'Food order Anda telah selesai.'],
                default => null,
            },
            Order::TYPE_SEND => match ($order->status) {
                Order::STATUS_DRIVER_ARRIVED => ['Driver telah tiba', 'Driver telah tiba di lokasi pickup barang.'],
                Order::STATUS_PICKED_UP => ['Barang sudah diambil', 'Driver telah mengambil barang Anda.'],
                Order::STATUS_DELIVERING => ['Barang sedang diantar', 'Driver sedang menuju alamat penerima.'],
                Order::STATUS_COMPLETED => ['Barang telah sampai', 'Pengiriman barang Anda telah selesai.'],
                default => null,
            },
            default => match ($order->status) {
                Order::STATUS_DRIVER_ARRIVED => ['Driver telah tiba', 'Driver telah tiba di lokasi pickup.'],
                Order::STATUS_COMPLETED => ['Perjalanan selesai', 'Ride Anda telah selesai.'],
                default => null,
            },
        };

        if ($copy) {
            $this->send($order->user, "{$order->type}_{$order->status}", $copy[0], $copy[1], $this->customerRoute($order), $order);
        }

        if ($order->type === Order::TYPE_FOOD && $order->status === Order::STATUS_COMPLETED && $order->merchant?->user) {
            $this->send($order->merchant->user, 'food_completed', 'Pesanan selesai', "{$order->order_number} telah diterima customer.", 'merchant_food_detail', $order);
        }
    }

    public function customerCancelled(Order $order): void
    {
        $order->loadMissing(['user', 'driver.user', 'merchant.user']);
        $this->send($order->user, "{$order->type}_cancelled", 'Order dibatalkan', "{$order->order_number} telah dibatalkan.", $this->customerRoute($order), $order);

        if ($order->driver?->user) {
            $this->send($order->driver->user, "{$order->type}_cancelled", 'Order dibatalkan', "{$order->order_number} dibatalkan oleh customer.", $this->driverRoute($order), $order);
        }
        if ($order->type === Order::TYPE_FOOD && $order->merchant?->user) {
            $this->send($order->merchant->user, 'food_cancelled', 'Pesanan dibatalkan', "{$order->order_number} dibatalkan oleh customer.", 'merchant_food_detail', $order);
        }
    }

    public function cashPaymentSettled(Order $order): void
    {
        $order->loadMissing('user');
        $this->send($order->user, "{$order->type}_payment_paid", 'Pembayaran diterima', "Pembayaran tunai {$order->order_number} telah diterima.", $this->customerRoute($order), $order);
    }

    private function customerRoute(Order $order): string
    {
        return match ($order->type) {
            Order::TYPE_FOOD => 'customer_food_detail',
            Order::TYPE_SEND => 'customer_send_detail',
            default => 'customer_ride_detail',
        };
    }

    private function driverRoute(Order $order): string
    {
        return match ($order->type) {
            Order::TYPE_FOOD => 'driver_food_detail',
            Order::TYPE_SEND => 'driver_send_detail',
            default => 'driver_ride_detail',
        };
    }

    private function send($user, string $type, string $title, string $body, string $route, Order $order): void
    {
        $this->push->notify($user, $type, $title, $body, [
            'type' => $type,
            'order_id' => $order->id,
            'order_type' => $order->type,
            'route' => $route,
        ]);
    }
}
