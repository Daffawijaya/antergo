<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverOrderController;
use App\Http\Controllers\Api\FoodOrderController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PushNotificationController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\SendOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'update']);
        Route::post('/avatar', [AuthController::class, 'updateAvatar']);
        Route::post('/update-customer-photo', [AuthController::class, 'updateCustomerPhoto']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/push-tokens', [PushNotificationController::class, 'registerToken']);
    Route::delete('/push-tokens', [PushNotificationController::class, 'unregisterToken']);
    Route::get('/notifications', [PushNotificationController::class, 'index']);
});
Route::middleware('auth:sanctum')->get('/driver/application', [DriverController::class, 'application']);
Route::middleware('auth:sanctum')->post('/driver/application', [DriverController::class, 'apply']);

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function () {
    Route::get('/profile', [DriverController::class, 'profile']);
    Route::post('/online', [DriverController::class, 'online']);
    Route::post('/offline', [DriverController::class, 'offline']);
    Route::post('/location', [DriverController::class, 'updateLocation']);
    Route::post('/vehicles', [DriverController::class, 'addVehicle']);
    Route::post('/vehicles/{vehicle}/active', [DriverController::class, 'setActiveVehicle']);
    Route::get('/documents', [DriverController::class, 'documents']);
    Route::post('/documents', [DriverController::class, 'updateDocument']);
    Route::delete('/documents/{type}', [DriverController::class, 'destroyDocument']);
});

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver/orders')->group(function () {
    Route::get('/active', [DriverOrderController::class, 'active']);
    Route::get('/history', [DriverOrderController::class, 'history']);
    Route::get('/available', [DriverOrderController::class, 'available']);
    Route::post('/{order}/accept', [DriverOrderController::class, 'accept']);
    Route::post('/{order}/status', [OrderController::class, 'status']);
    Route::post('/{order}/payments/cash/settle', [PaymentController::class, 'settleCash']);
});

Route::middleware(['auth:sanctum', 'role:customer'])->prefix('orders')->group(function () {
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/{order}/rating', [RatingController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:customer,driver,merchant,admin'])
    ->get('/orders/{order}', [OrderController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:customer,driver'])->group(function () {
    Route::get('/chats', [ChatController::class, 'conversations']);
    Route::get('/orders/{order}/messages', [ChatController::class, 'index']);
    Route::post('/orders/{order}/messages', [ChatController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:customer'])->post('/send/orders', [SendOrderController::class, 'store']);

Route::middleware(['auth:sanctum', 'role:customer'])->prefix('food/orders')->group(function () {
    Route::post('/', [FoodOrderController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:customer'])->post('/shopping/orders', [FoodOrderController::class, 'storeShopping']);

Route::get('/geocode', [GeocodeController::class, 'search']);
Route::get('/geocode/reverse', [GeocodeController::class, 'reverse']);
Route::get('/geocode/nearby', [GeocodeController::class, 'nearby']);

Route::get('/merchant-categories', [MerchantController::class, 'categories']);
Route::get('/merchants', [MerchantController::class, 'index']);
Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);

Route::middleware('auth:sanctum')->post('/merchant', [MerchantController::class, 'store']);

Route::middleware(['auth:sanctum', 'role:merchant'])->prefix('merchant')->group(function () {
    Route::get('/me', [MerchantController::class, 'myMerchant']);
    Route::post('/image', [MerchantController::class, 'updateImage']);
    Route::post('/cover-image', [MerchantController::class, 'updateCoverImage']);
    Route::delete('/cover-image', [MerchantController::class, 'destroyCoverImage']);
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
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/users/{user}', [AdminController::class, 'user']);
    Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus']);
    Route::get('/drivers', [AdminController::class, 'drivers']);
    Route::get('/drivers/{driver}', [AdminController::class, 'driver']);
    Route::patch('/drivers/{driver}/status', [AdminController::class, 'updateDriverStatus']);

    Route::get('/merchants', [AdminController::class, 'merchants']);
    Route::get('/merchants/{merchant}', [AdminController::class, 'merchant']);
    Route::patch('/merchants/{merchant}/status', [AdminController::class, 'updateMerchantStatus']);
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::get('/orders/{order}', [AdminController::class, 'order']);
});
