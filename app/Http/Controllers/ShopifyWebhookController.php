<?php

namespace App\Http\Controllers;

use App\Models\Hat;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyWebhookController extends Controller
{
    /**
     * Handle the Shopify "orders/create" webhook.
     *
     * Shopify requires a fast 200 response regardless of whether we were
     * able to successfully process the payload, so all processing is
     * wrapped in a try/catch and failures are only logged/recorded.
     */
    public function ordersCreate(Request $request): JsonResponse
    {
        $payload = (array) $request->json()->all();

        $status = 'success';
        $error = null;

        try {
            $this->processOrder($payload);
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();

            Log::error('Failed to process Shopify orders/create webhook.', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        WebhookEvent::create([
            'topic' => 'orders/create',
            'payload' => $payload,
            'status' => $status,
            'error' => $error,
            'processed_at' => now(),
        ]);

        return response()->json(['received' => true], 200);
    }

    /**
     * Extract order data from the payload and persist it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function processOrder(array $payload): void
    {
        if (empty($payload['id'])) {
            throw new \RuntimeException('Missing Shopify order id in payload.');
        }

        $shopifyOrderId = (string) $payload['id'];

        $customerEmail = $payload['email'] ?? ($payload['customer']['email'] ?? null);

        $customerName = null;
        if (! empty($payload['customer'])) {
            $firstName = $payload['customer']['first_name'] ?? '';
            $lastName = $payload['customer']['last_name'] ?? '';
            $customerName = trim("{$firstName} {$lastName}") ?: null;
        }

        $lineItems = $payload['line_items'] ?? [];
        $firstLineItem = $lineItems[0] ?? [];

        $quantity = $firstLineItem['quantity'] ?? 1;

        $orderNumber = $payload['order_number'] ?? ($payload['name'] ?? null);
        $orderNumber = $orderNumber !== null ? (string) $orderNumber : null;

        $hatId = null;
        if (! empty($firstLineItem['title'])) {
            $hat = Hat::whereRaw('LOWER(name) = ?', [mb_strtolower($firstLineItem['title'])])->first();
            $hatId = $hat?->id;
        }

        Order::updateOrCreate(
            ['shopify_order_id' => $shopifyOrderId],
            [
                'hat_id' => $hatId,
                'customer_email' => $customerEmail,
                'customer_name' => $customerName,
                'order_number' => $orderNumber,
                'total_price' => $payload['total_price'] ?? 0,
                'currency' => $payload['currency'] ?? 'USD',
                'status' => $payload['financial_status'] ?? 'pending',
                'quantity' => $quantity,
                'order_data' => $payload,
            ]
        );
    }
}
