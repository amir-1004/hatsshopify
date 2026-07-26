<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.shopify.webhook_secret');

        if (empty($secret)) {
            abort(500, 'Shopify webhook secret is not configured.');
        }

        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            abort(401, 'Missing Shopify HMAC signature.');
        }

        $rawBody = $request->getContent();
        $computedHmac = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        if (! hash_equals($computedHmac, $hmacHeader)) {
            abort(401, 'Invalid Shopify HMAC signature.');
        }

        return $next($request);
    }
}
