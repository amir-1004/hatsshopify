<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper around the Printful API (print-on-demand catalog, mockup
 * generation, and draft orders for the Design Studio).
 *
 * Same never-throw philosophy as ClaudeService: every public method
 * degrades to null/[] on any failure (missing API key, HTTP error, timeout,
 * unexpected response shape) so Design Studio features never crash the rest
 * of the product.
 */
class PrintfulService
{
    protected const BASE_URL = 'https://api.printful.com';

    /** Headwear category id in Printful's catalog. */
    protected const HEADWEAR_CATEGORY_ID = 24;

    /**
     * Curated headwear catalog, cached for 1 hour.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCatalogProducts(): array
    {
        $result = Cache::remember('printful:catalog:products', 3600, function () {
            $response = $this->get('/products', ['category_id' => self::HEADWEAR_CATEGORY_ID]);

            if ($response === null) {
                return null;
            }

            $result = $response->json('result');

            return is_array($result) ? $result : [];
        });

        return is_array($result) ? $result : [];
    }

    /**
     * A single catalog product with its color/style variants, cached for 1
     * hour.
     *
     * @return array{product: array<string, mixed>, variants: array<int, array<string, mixed>>}|null
     */
    public function getProduct(int $id): ?array
    {
        $result = Cache::remember("printful:product:{$id}", 3600, function () use ($id) {
            $response = $this->get("/products/{$id}");

            if ($response === null) {
                return null;
            }

            $data = $response->json('result');

            if (! is_array($data) || ! isset($data['product'])) {
                return null;
            }

            return [
                'product' => $data['product'],
                'variants' => $data['variants'] ?? [],
            ];
        });

        return is_array($result) ? $result : null;
    }

    /**
     * Kick off an async mockup-generation task for a product/variant + design
     * image. Returns the Printful task key, or null on failure.
     */
    public function createMockupTask(int $productId, array $variantIds, string $imageUrl, string $placement = 'embroidery_front'): ?string
    {
        $response = $this->post("/mockup-generator/create-task/{$productId}", [
            'variant_ids' => $variantIds,
            'format' => 'jpg',
            'files' => [
                ['placement' => $placement, 'image_url' => $imageUrl],
            ],
        ]);

        if ($response === null) {
            return null;
        }

        $taskKey = $response->json('result.task_key');

        return is_string($taskKey) && $taskKey !== '' ? $taskKey : null;
    }

    /**
     * Poll a mockup-generation task.
     *
     * @return array{status: string, mockups: array<int, array<string, mixed>>}|null
     */
    public function getMockupTask(string $taskKey): ?array
    {
        $response = $this->get('/mockup-generator/task', ['task_key' => $taskKey]);

        if ($response === null) {
            return null;
        }

        $result = $response->json('result');

        return is_array($result) ? $result : null;
    }

    /**
     * Create a draft (unconfirmed, unpaid) Printful order. Printful orders
     * default to draft when no `confirm` param is sent — this method never
     * sends one, so nothing here can trigger real fulfillment/payment.
     *
     * @param  array<string, mixed>  $recipient
     * @return array<string, mixed>|null
     */
    public function createDraftOrder(array $recipient, int $variantId, int $quantity, string $imageUrl, string $hatName): ?array
    {
        $response = $this->post('/orders', [
            'recipient' => $recipient,
            'items' => [
                [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'name' => $hatName,
                    // Orders API expects `type` for the placement (unlike the
                    // mockup API, which uses `placement`).
                    'files' => [
                        ['type' => 'embroidery_front', 'url' => $imageUrl],
                    ],
                ],
            ],
        ]);

        if ($response === null) {
            return null;
        }

        $result = $response->json('result');

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $path, array $query = []): ?Response
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $path, array $body = []): ?Response
    {
        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $path, array $payload): ?Response
    {
        $apiKey = config('services.printful.key');

        if (empty($apiKey)) {
            return null;
        }

        try {
            $request = Http::withToken($apiKey)->timeout(20);

            // Account-level tokens must name the store; store-level tokens
            // don't need the header.
            if ($storeId = config('services.printful.store_id')) {
                $request = $request->withHeaders(['X-PF-Store-Id' => $storeId]);
            }

            $response = $request->{$method}(self::BASE_URL.$path, $payload);

            if ($response->failed()) {
                Log::warning('Printful API request failed.', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response;
        } catch (Throwable $e) {
            Log::warning('Printful API request threw an exception.', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
