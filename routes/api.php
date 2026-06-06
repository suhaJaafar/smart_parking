<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// WhatsApp OTP login — passwordless auth for accounts created via the bot.
// Throttled per-IP to blunt enumeration / brute-force; the service layer
// adds its own per-phone cooldown and per-code attempt cap.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/whatsapp/request-code', [AuthController::class, 'requestWhatsAppCode']);
    Route::post('auth/whatsapp/verify-code',  [AuthController::class, 'verifyWhatsAppCode']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);

      // users routes — privileged user management, SUPER_ADMIN only.
    Route::prefix('users')->group(function () {
        Route::middleware('role:SUPER_ADMIN')->group(function () {
            Route::get('/', [App\Http\Controllers\UserController::class, 'index']);
            Route::post('/', [App\Http\Controllers\UserController::class, 'store']);
            Route::get('{id}', [App\Http\Controllers\UserController::class, 'show']);
            Route::put('{id}', [App\Http\Controllers\UserController::class, 'update']);
            Route::delete('{id}', [App\Http\Controllers\UserController::class, 'destroy']);
        });
    });

    // parking routes
    Route::prefix('parks')->group(function () {
        // Collection routes
        Route::get('/', [App\Http\Controllers\ParkController::class, 'index']);
        Route::post('/', [App\Http\Controllers\ParkController::class, 'store']);
        Route::get('user', [App\Http\Controllers\ParkController::class, 'userParks']);
        Route::get('{id}', [App\Http\Controllers\ParkController::class, 'show']);
        Route::put('{id}', [App\Http\Controllers\ParkController::class, 'update']);
        Route::delete('{id}', [App\Http\Controllers\ParkController::class, 'destroy']);
        Route::post('{id}/entercar', [App\Http\Controllers\ParkController::class, 'enterCar']);
        Route::post('{id}/exitcar', [App\Http\Controllers\ParkController::class, 'exitCar']);
    });


    // Admin-only routes
    Route::middleware('role:ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('admin/stats', [App\Http\Controllers\AdminController::class, 'stats']);
    });

    // Space-owner routes — scoped to the authenticated user's own parks.
    Route::middleware('role:SPACE_OWNER,SUPER_ADMIN')->group(function () {
        Route::get('owner/stats', [App\Http\Controllers\OwnerController::class, 'stats']);
    });

    // Customer-only routes
    Route::middleware('role:USER')->group(function () {
        Route::get('customer/parks/nearby', [App\Http\Controllers\CustomerController::class, 'nearbyParks']);
    });
});

// ===============================
// WHATSAPP WEBHOOK ENDPOINTS
// ===============================
Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'receive'])
    ->middleware('whatsapp.signed');
Route::post('/payments/qicard/webhook', [PaymentController::class, 'webhook']);
