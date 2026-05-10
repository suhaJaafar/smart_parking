<?php

use App\Http\Controllers\ParkMapController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/parks/map', [ParkMapController::class, 'show'])->name('parks.map');
