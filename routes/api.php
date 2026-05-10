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
    Route::middleware('role:SUPER_ADMIN')->group(function () {
        Route::get('users', [App\Http\Controllers\UserController::class, 'index']);
        Route::post('users', [App\Http\Controllers\UserController::class, 'store']);
        Route::get('users/{id}', [App\Http\Controllers\UserController::class, 'show']);
        Route::put('users/{id}', [App\Http\Controllers\UserController::class, 'update']);
        Route::delete('users/{id}', [App\Http\Controllers\UserController::class, 'destroy']);
    });

    // parking routes
    Route::post('parks', [App\Http\Controllers\ParkController::class, 'store']);
    Route::get('parks', [App\Http\Controllers\ParkController::class, 'index']);
    Route::get('parks/{id}', [App\Http\Controllers\ParkController::class, 'show']);
    Route::put('parks/{id}', [App\Http\Controllers\ParkController::class, 'update']);
    Route::delete('parks/{id}', [App\Http\Controllers\ParkController::class, 'destroy']);
    Route::get('user/parks', [App\Http\Controllers\ParkController::class, 'userParks']);
    Route::post('parks/{id}/entercar', [App\Http\Controllers\ParkController::class, 'enterCar']);
    Route::post('parks/{id}/exitcar', [App\Http\Controllers\ParkController::class, 'exitCar']);




    // Admin-only routes
    Route::middleware('role:ADMIN,SUPER_ADMIN')->group(function () {
        // Route::get('admin/users', [AdminController::class, 'index']);
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
