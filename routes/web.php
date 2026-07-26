<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/webhook/shopify/orders-create', [ShopifyWebhookController::class, 'ordersCreate'])
    ->middleware(VerifyShopifyWebhook::class);
