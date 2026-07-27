<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesignFileController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StudioController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/studio', [StudioController::class, 'index'])->name('studio');

// Public so Printful's mockup generator / order API can fetch design bytes by URL.
Route::get('/design-files/{designFile}', [DesignFileController::class, 'show'])->name('design-files.show');

Route::post('/webhook/shopify/orders-create', [ShopifyWebhookController::class, 'ordersCreate'])
    ->middleware(VerifyShopifyWebhook::class);
