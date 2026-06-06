<?php

use App\Http\Controllers\ParkMapController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/parks/map', [ParkMapController::class, 'show'])->name('parks.map');

Route::get('/pay/{token}', [PaymentController::class, 'redirect'])->name('payments.redirect');
Route::get('/payments/return', [PaymentController::class, 'return'])->name('payments.return');

