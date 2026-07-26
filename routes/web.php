<?php

use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhook/shopify/orders-create', [ShopifyWebhookController::class, 'ordersCreate'])
    ->middleware(VerifyShopifyWebhook::class);
