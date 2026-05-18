<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;


Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

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
