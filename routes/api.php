<?php

use App\Http\Controllers\Api\DesignFileController;
use App\Http\Controllers\Api\HatController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PrintfulController;
use App\Http\Controllers\Api\TryOnController;
use Illuminate\Support\Facades\Route;

Route::get('/hats', [HatController::class, 'index']);
Route::post('/hats', [HatController::class, 'store']);
Route::post('/hats/generate-description', [HatController::class, 'generateDescription']);
Route::post('/hats/{hat}/mockup', [PrintfulController::class, 'mockup']);
Route::post('/hats/{hat}/supplier-order', [PrintfulController::class, 'supplierOrder']);
Route::get('/hats/{hat}', [HatController::class, 'show']);
Route::put('/hats/{hat}', [HatController::class, 'update']);
Route::delete('/hats/{hat}', [HatController::class, 'destroy']);

// Must be registered before /orders/{order} so "insights" isn't captured as an order id.
Route::get('/orders/insights', [OrderController::class, 'insights']);
Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{order}', [OrderController::class, 'show']);

// Virtual try-on: pixel measurements in, hat size out. No image data.
Route::post('/try-on/recommend', [TryOnController::class, 'recommend']);

// Printful print-on-demand (Design Studio).
Route::get('/printful/products', [PrintfulController::class, 'catalogProducts']);
Route::get('/printful/products/{id}', [PrintfulController::class, 'product']);
Route::post('/design-files', [DesignFileController::class, 'store']);
Route::get('/mockup-tasks/{taskKey}', [PrintfulController::class, 'mockupTask']);
