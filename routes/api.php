<?php

use App\Bots\Channels\Telegram\TelegramController;
use App\Bots\Channels\WhatsApp\WhatsAppController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminParkApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CoOwnerRequestController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerReservationController;
use App\Http\Controllers\MiniAppProfileController;
use App\Http\Controllers\OwnerCarController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\OwnerParkUserController;
use App\Http\Controllers\OwnerReservationController;
use App\Http\Controllers\ParkController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReservationStatsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// login and send otp for whatsapp / telegram accounts creates by bot .
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/whatsapp/request-code', [AuthController::class, 'requestWhatsAppCode']);
    Route::post('auth/whatsapp/verify-code', [AuthController::class, 'verifyWhatsAppCode']);
    Route::post('auth/telegram/verify-code', [AuthController::class, 'verifyTelegramCode']);

    // Telegram Mini App: exchange Telegram-signed `initData` for a JWT.
    // Throttled like the other code endpoints — a forged payload costs an
    // HMAC, so the limit blunts brute-force probing of the signature.
    Route::post('auth/telegram/miniapp', [AuthController::class, 'verifyTelegramMiniApp']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Mini App: switch between driver and garage owner. Roles are exclusive,
    // mirroring the bot's onboarding.
    Route::post('miniapp/role', [MiniAppProfileController::class, 'switchRole']);

    // users routes — privileged user management, SUPER_ADMIN only.
    Route::prefix('users')->group(function () {
        Route::middleware('role:SUPER_ADMIN')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('{id}', [UserController::class, 'show']);
            Route::put('{id}', [UserController::class, 'update']);
            Route::delete('{id}', [UserController::class, 'destroy']);
        });
    });

    // parking routes
    Route::prefix('parks')->group(function () {
        // Collection routes
        Route::get('/', [ParkController::class, 'index']);
        Route::post('/', [ParkController::class, 'store']);
        Route::get('user', [ParkController::class, 'userParks']);
        Route::get('{id}', [ParkController::class, 'show']);
        Route::put('{id}', [ParkController::class, 'update']);
        Route::delete('{id}', [ParkController::class, 'destroy']);
        Route::post('{id}/entercar', [ParkController::class, 'enterCar']);
        Route::post('{id}/exitcar', [ParkController::class, 'exitCar']);
    });

    // Admin-only routes
    Route::middleware('role:ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('admin/stats', [AdminController::class, 'stats']);

        // Platform-wide reservations analytics.
        Route::get('admin/reservation-stats', [ReservationStatsController::class, 'admin']);

        // Review queue for newly registered garages.
        Route::get('admin/park-approvals', [AdminParkApprovalController::class, 'index']);
        Route::post('admin/park-approvals/{id}/approve', [AdminParkApprovalController::class, 'approve']);
        Route::post('admin/park-approvals/{id}/reject', [AdminParkApprovalController::class, 'reject']);
    });

    // Space-owner routes — scoped to the authenticated user's own parks.
    Route::middleware('role:SPACE_OWNER,SUPER_ADMIN')->group(function () {
        Route::get('owner/stats', [OwnerController::class, 'stats']);

        // Co-owner requests — people asking to co-manage the owner's garages.
        Route::get('owner/co-owner-requests', [CoOwnerRequestController::class, 'index']);
        Route::post('owner/co-owner-requests/{id}/approve', [CoOwnerRequestController::class, 'approve']);
        Route::post('owner/co-owner-requests/{id}/reject', [CoOwnerRequestController::class, 'reject']);

        // Cars inside the owner's garages — full CRUD, scoped to owned parks.
        Route::get('owner/cars', [OwnerCarController::class, 'index']);
        Route::post('owner/cars', [OwnerCarController::class, 'store']);
        // Historical parking sessions (cars that entered & left) + CSV export.
        // Registered before `owner/cars/{id}` so the static segments win.
        Route::get('owner/park-cars/history', [OwnerCarController::class, 'history']);
        Route::get('owner/park-cars/history/export', [OwnerCarController::class, 'exportHistory']);
        Route::get('owner/cars/{id}', [OwnerCarController::class, 'show']);
        Route::put('owner/cars/{id}', [OwnerCarController::class, 'update']);
        Route::delete('owner/cars/{id}', [OwnerCarController::class, 'destroy']);

        // Customers who have ever reserved at the owner's garages + CSV export.
        Route::get('owner/park-users', [OwnerParkUserController::class, 'index']);
        Route::get('owner/park-users/export', [OwnerParkUserController::class, 'export']);

        // Reservations across the owner's garages. Read-only browsing plus
        // two lifecycle transitions (cancel a hold, exit a car) that reuse
        // the exact services the bot uses — no delete, ever.
        // `export` is registered before `{id}` so it isn't captured as an id.
        Route::get('owner/reservations', [OwnerReservationController::class, 'index']);
        Route::get('owner/reservations/export', [OwnerReservationController::class, 'export']);
        Route::post('owner/walk-in', [OwnerReservationController::class, 'walkIn']);
        Route::get('owner/reservations/{id}', [OwnerReservationController::class, 'show']);
        Route::post('owner/reservations/{id}/admit', [OwnerReservationController::class, 'admit']);
        Route::post('owner/reservations/{id}/cancel', [OwnerReservationController::class, 'cancel']);
        Route::post('owner/reservations/{id}/exit', [OwnerReservationController::class, 'exitCar']);

        // Owner-scoped reservations analytics.
        Route::get('owner/reservation-stats', [ReservationStatsController::class, 'owner']);
    });

    // Customer-facing routes.
    //
    // Open to every signed-in role: web sign-ups get USER, bot/Mini App
    // accounts get CUSTOMER, and a garage owner parks at other people's
    // garages like anyone else. Every endpoint below is already scoped to the
    // caller's own data, so the role is not what protects them.
    Route::middleware('role:USER,CUSTOMER,SPACE_OWNER,ADMIN,SUPER_ADMIN')->group(function () {
        Route::get('customer/parks/nearby', [CustomerController::class, 'nearbyParks']);

        // Reservations the customer makes for themselves.
        Route::get('customer/reservations', [CustomerReservationController::class, 'index']);
        Route::get('customer/reservations/active', [CustomerReservationController::class, 'active']);
        Route::post('customer/reservations', [CustomerReservationController::class, 'store']);
        Route::post('customer/reservations/{id}/cancel', [CustomerReservationController::class, 'cancel']);
        Route::post('customer/reservations/{id}/pay-cash', [CustomerReservationController::class, 'payCash']);
    });
});

// ===============================
// WHATSAPP WEBHOOK ENDPOINTS
// ===============================
Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'receive'])
    ->middleware('whatsapp.signed');

// ===============================
// TELEGRAM WEBHOOK ENDPOINT
// ===============================
// Telegram has no GET handshake — webhooks are registered out-of-band via
// the Bot API's `setWebhook` endpoint. Auth is the secret token echoed
// back in the `X-Telegram-Bot-Api-Secret-Token` header on every delivery.
Route::post('/telegram/webhook', [TelegramController::class, 'receive'])
    ->middleware('telegram.signed');

// ===============================
// QICARD PAYMENT WEBHOOK
// ===============================
// Server-to-server notification from QiCard. We never trust the body —
// we look up the row by paymentId/requestId, re-fetch authoritative
// status from Qi, and always ACK 200 (Qi doesn't read our response).
Route::post('/payments/qicard/webhook', [PaymentController::class, 'webhook']);
