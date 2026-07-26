<?php

namespace Tests\Feature;

use App\Models\Hat;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_index_returns_orders_with_nested_hat_data(): void
    {
        $hat = Hat::factory()->create(['name' => 'Black Trucker Hat']);
        Order::factory()->create(['hat_id' => $hat->id]);

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'shopify_order_id', 'hat' => ['id', 'name']],
            ],
            'links',
            'total',
            'current_page',
            'per_page',
        ]);
        $response->assertJsonFragment(['name' => 'Black Trucker Hat']);
    }

    public function test_show_returns_the_order_with_its_hat(): void
    {
        $hat = Hat::factory()->create(['name' => 'Ocean Snapback']);
        $order = Order::factory()->create(['hat_id' => $hat->id]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Ocean Snapback']);
        $response->assertJsonPath('id', $order->id);
    }

    public function test_insights_returns_ai_summary_when_claude_succeeds(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        $hat = Hat::factory()->create(['name' => 'Black Trucker Hat']);
        Order::factory()->count(2)->create(['hat_id' => $hat->id, 'currency' => 'USD']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Orders are trending up, with Black Trucker Hat leading sales.'],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/orders/insights');

        $response->assertStatus(200);
        $response->assertJsonStructure(['summary', 'ai', 'stats']);
        $response->assertJson([
            'ai' => true,
            'summary' => 'Orders are trending up, with Black Trucker Hat leading sales.',
        ]);
    }

    public function test_insights_falls_back_to_deterministic_summary_when_claude_fails(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        $hatA = Hat::factory()->create(['name' => 'Black Trucker Hat']);
        $hatB = Hat::factory()->create(['name' => 'Ocean Snapback']);

        Order::factory()->count(2)->create(['hat_id' => $hatA->id, 'currency' => 'USD', 'total_price' => 100]);
        Order::factory()->create(['hat_id' => $hatB->id, 'currency' => 'USD', 'total_price' => 50]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $response = $this->getJson('/api/orders/insights');

        $response->assertStatus(200);
        $response->assertJson([
            'ai' => false,
            'summary' => '3 orders totaling 250.00 USD. Best seller: Black Trucker Hat.',
        ]);
    }

    public function test_insights_response_is_cached(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        Order::factory()->create();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'First summary.']],
            ], 200),
        ]);

        $this->getJson('/api/orders/insights')->assertJson(['summary' => 'First summary.']);

        // Even if a new order is created and Claude would respond differently,
        // the cached payload should be returned for 10 minutes.
        Order::factory()->create();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Second summary.']],
            ], 200),
        ]);

        $this->getJson('/api/orders/insights')->assertJson(['summary' => 'First summary.']);
    }
}
