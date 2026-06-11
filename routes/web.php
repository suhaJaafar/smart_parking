<?php

use App\Http\Controllers\ParkMapController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/parks/map', [ParkMapController::class, 'show'])->name('parks.map');

// Customer-facing payment endpoints. `/pay/{token}` is the short URL we
// hand to the customer in their post-entry bot message; it lazily
// provisions a Qi formUrl and redirects. `/payments/return` is the page
// Qi sends the customer back to after the hosted form.
Route::get('/pay/{token}',       [PaymentController::class, 'redirect'])->name('payments.redirect');
Route::get('/payments/return',   [PaymentController::class, 'return'])->name('payments.return');
