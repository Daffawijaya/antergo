<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverOrderController;
use App\Http\Controllers\Api\FoodOrderController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function () {
    Route::get('/profile', [DriverController::class, 'profile']);
    Route::post('/online', [DriverController::class, 'online']);
    Route::post('/offline', [DriverController::class, 'offline']);
    Route::post('/location', [DriverController::class, 'updateLocation']);
});

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver/orders')->group(function () {
    Route::get('/available', [DriverOrderController::class, 'available']);
    Route::post('/{order}/accept', [DriverOrderController::class, 'accept']);
    Route::post('/{order}/status', [OrderController::class, 'status']);
});

Route::middleware(['auth:sanctum', 'role:customer'])->prefix('orders')->group(function () {
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:customer,driver,merchant,admin'])
    ->get('/orders/{order}', [OrderController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:customer'])->prefix('food/orders')->group(function () {
    Route::post('/', [FoodOrderController::class, 'store']);
});

Route::get('/merchants', [MerchantController::class, 'index']);
Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:merchant'])->prefix('merchant')->group(function () {
    Route::get('/me', [MerchantController::class, 'myMerchant']);
    Route::post('/', [MerchantController::class, 'store']);
    Route::post('/open', [MerchantController::class, 'open']);
    Route::post('/close', [MerchantController::class, 'close']);

    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/orders', [FoodOrderController::class, 'merchantOrders']);
    Route::post('/orders/{order}/confirm', [FoodOrderController::class, 'confirm']);
    Route::post('/orders/{order}/status', [FoodOrderController::class, 'updateStatus']);
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
