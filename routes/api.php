<?php

use App\Http\Controllers\Api\HatController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/hats', [HatController::class, 'index']);
Route::post('/hats', [HatController::class, 'store']);
Route::post('/hats/generate-description', [HatController::class, 'generateDescription']);
Route::get('/hats/{hat}', [HatController::class, 'show']);
Route::put('/hats/{hat}', [HatController::class, 'update']);
Route::delete('/hats/{hat}', [HatController::class, 'destroy']);

// Must be registered before /orders/{order} so "insights" isn't captured as an order id.
Route::get('/orders/insights', [OrderController::class, 'insights']);
Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
