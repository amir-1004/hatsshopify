<?php

namespace Tests\Feature;

use App\Models\Hat;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'test-shopify-webhook-secret';

    protected string $endpoint = '/webhook/shopify/orders-create';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.webhook_secret' => $this->secret]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function samplePayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 820982911946154508,
            'order_number' => 1001,
            'email' => 'jon@example.com',
            'total_price' => '199.99',
            'currency' => 'USD',
            'financial_status' => 'paid',
            'customer' => [
                'first_name' => 'Jon',
                'last_name' => 'Doe',
                'email' => 'jon@example.com',
            ],
            'line_items' => [
                [
                    'title' => 'Black Trucker Hat',
                    'sku' => 'BLK-TRK-01',
                    'quantity' => 2,
                ],
            ],
        ], $overrides);
    }

    protected function hmacFor(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->secret, true));
    }

    protected function postWebhook(string $body, ?string $hmac, array $extraHeaders = []): \Illuminate\Testing\TestResponse
    {
        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
        ], $extraHeaders);

        if ($hmac !== null) {
            $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = $hmac;
        }

        return $this->call('POST', $this->endpoint, [], [], [], $headers, $body);
    }

    public function test_visiting_webhook_url_in_a_browser_returns_explanatory_405_json(): void
    {
        $response = $this->get($this->endpoint, ['Accept' => 'text/html']);

        $response->assertStatus(405);
        $response->assertJson(fn ($json) => $json->whereType('message', 'string')->etc());
        $this->assertStringContainsString('POST', $response->json('message'));
        $this->assertStringContainsString('Shopify', $response->json('message'));
    }

    public function test_valid_hmac_signature_returns_200_and_persists_order(): void
    {
        $payload = $this->samplePayload();
        $body = json_encode($payload);
        $hmac = $this->hmacFor($body);

        $response = $this->postWebhook($body, $hmac);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        $this->assertDatabaseHas('orders', [
            'shopify_order_id' => '820982911946154508',
            'customer_email' => 'jon@example.com',
            'customer_name' => 'Jon Doe',
            'currency' => 'USD',
            'status' => 'paid',
            'quantity' => 2,
        ]);

        $order = Order::where('shopify_order_id', '820982911946154508')->first();
        $this->assertNotNull($order);
        $this->assertEquals(199.99, (float) $order->total_price);
    }

    public function test_invalid_signature_returns_401_and_no_order_created(): void
    {
        $payload = $this->samplePayload();
        $body = json_encode($payload);

        $response = $this->postWebhook($body, 'this-is-not-a-valid-signature');

        $response->assertStatus(401);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_signature_header_returns_401(): void
    {
        $payload = $this->samplePayload();
        $body = json_encode($payload);

        $response = $this->postWebhook($body, null);

        $response->assertStatus(401);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_tampered_payload_returns_401(): void
    {
        $originalBody = json_encode($this->samplePayload());
        $hmac = $this->hmacFor($originalBody);

        $tamperedBody = json_encode($this->samplePayload(['total_price' => '999999.99']));

        $response = $this->postWebhook($tamperedBody, $hmac);

        $response->assertStatus(401);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_delivery_only_creates_one_order(): void
    {
        $payload = $this->samplePayload();
        $body = json_encode($payload);
        $hmac = $this->hmacFor($body);

        $this->postWebhook($body, $hmac)->assertStatus(200);
        $this->postWebhook($body, $hmac)->assertStatus(200);

        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(1, Order::where('shopify_order_id', '820982911946154508')->count());
    }

    public function test_matching_line_item_title_links_hat(): void
    {
        $hat = Hat::factory()->create(['name' => 'Black Trucker Hat']);

        $payload = $this->samplePayload();
        $body = json_encode($payload);
        $hmac = $this->hmacFor($body);

        $this->postWebhook($body, $hmac)->assertStatus(200);

        $order = Order::where('shopify_order_id', '820982911946154508')->first();
        $this->assertNotNull($order);
        $this->assertEquals($hat->id, $order->hat_id);
    }

    public function test_no_matching_hat_leaves_hat_id_null(): void
    {
        $payload = $this->samplePayload();
        $body = json_encode($payload);
        $hmac = $this->hmacFor($body);

        $this->postWebhook($body, $hmac)->assertStatus(200);

        $order = Order::where('shopify_order_id', '820982911946154508')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->hat_id);
    }

    public function test_unconfigured_secret_fails_closed_with_500(): void
    {
        config(['services.shopify.webhook_secret' => null]);

        $body = json_encode($this->samplePayload());

        $response = $this->postWebhook($body, $this->hmacFor($body));

        $response->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_hat_title_match_is_case_insensitive(): void
    {
        $hat = Hat::factory()->create(['name' => 'BLACK trucker HAT']);

        $body = json_encode($this->samplePayload());

        $this->postWebhook($body, $this->hmacFor($body))->assertStatus(200);

        $order = Order::where('shopify_order_id', '820982911946154508')->first();
        $this->assertEquals($hat->id, $order->hat_id);
    }

    public function test_successful_webhook_logs_success_event(): void
    {
        $body = json_encode($this->samplePayload());

        $this->postWebhook($body, $this->hmacFor($body))->assertStatus(200);

        $event = WebhookEvent::first();
        $this->assertEquals('success', $event->status);
        $this->assertNotNull($event->processed_at);
    }

    public function test_malformed_payload_still_returns_200_and_logs_failed_webhook_event(): void
    {
        $payload = $this->samplePayload();
        unset($payload['id']);
        $body = json_encode($payload);
        $hmac = $this->hmacFor($body);

        $response = $this->postWebhook($body, $hmac);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('webhook_events', [
            'topic' => 'orders/create',
            'status' => 'failed',
        ]);

        $event = WebhookEvent::first();
        $this->assertNotNull($event);
        $this->assertNotNull($event->error);
        $this->assertNotNull($event->processed_at);
    }
}
