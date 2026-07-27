<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesignFileController;
use App\Http\Controllers\HatArtController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\TryOnController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/studio', [StudioController::class, 'index'])->name('studio');

// Generated hat artwork (SVG) so no hat product is ever without a picture.
Route::get('/hat-art/{style}', [HatArtController::class, 'show'])->name('hat-art.show');

// Virtual try-on. The optional {hat} pre-selects a hat from the catalog.
Route::get('/try-on/{hat?}', [TryOnController::class, 'index'])->name('try-on');

// Public so Printful's mockup generator / order API can fetch design bytes by URL.
Route::get('/design-files/{designFile}', [DesignFileController::class, 'show'])->name('design-files.show');

Route::post('/webhook/shopify/orders-create', [ShopifyWebhookController::class, 'ordersCreate'])
    ->middleware(VerifyShopifyWebhook::class);

// Browsers (and curious humans) send GET — answer with an explanation
// instead of a bare framework error page.
Route::get('/webhook/shopify/orders-create', fn () => response()->json([
    'message' => 'This is the Shopify orders/create webhook endpoint. It only accepts '
        .'POST requests signed by Shopify (X-Shopify-Hmac-SHA256) — seeing this '
        .'message in a browser is expected.',
], 405));
