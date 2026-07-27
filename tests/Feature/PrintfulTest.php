<?php

namespace Tests\Feature;

use App\Models\DesignFile;
use App\Models\Hat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrintfulTest extends TestCase
{
    use RefreshDatabase;

    // A 1x1 transparent PNG, used as a small, valid image payload for uploads.
    protected const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function test_design_file_upload_via_data_url_stores_and_serves_bytes(): void
    {
        $binary = base64_decode(self::TINY_PNG_BASE64);
        $dataUrl = 'data:image/png;base64,'.self::TINY_PNG_BASE64;

        $response = $this->postJson('/api/design-files', ['data_url' => $dataUrl]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'url']);

        $id = $response->json('id');

        $this->assertDatabaseHas('design_files', [
            'id' => $id,
            'mime' => 'image/png',
            'byte_size' => strlen($binary),
        ]);

        $publicResponse = $this->get($response->json('url'));

        $publicResponse->assertStatus(200);
        $publicResponse->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($binary, $publicResponse->getContent());
    }

    public function test_mockup_endpoint_creates_task_and_updates_hat(): void
    {
        config(['services.printful.key' => 'test-key']);

        Http::fake([
            'api.printful.com/mockup-generator/create-task/*' => Http::response([
                'result' => ['task_key' => 'abc123'],
            ], 200),
        ]);

        $hat = Hat::factory()->create();
        $designFile = DesignFile::factory()->create();

        $response = $this->postJson("/api/hats/{$hat->id}/mockup", [
            'printful_product_id' => 206,
            'printful_variant_id' => 4011,
            'design_file_id' => $designFile->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['task_key' => 'abc123']);

        $this->assertDatabaseHas('hats', [
            'id' => $hat->id,
            'printful_product_id' => 206,
            'printful_variant_id' => 4011,
            'design_file_id' => $designFile->id,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/mockup-generator/create-task/206')
                && $request['variant_ids'] === [4011]
                && ! array_key_exists('confirm', $request->data());
        });
    }

    public function test_mockup_task_completed_saves_mockup_urls_on_hat(): void
    {
        config(['services.printful.key' => 'test-key']);

        Http::fake([
            'api.printful.com/mockup-generator/task*' => Http::response([
                'result' => [
                    'status' => 'completed',
                    'mockups' => [
                        ['mockup_url' => 'https://files.printful.com/mockup1.jpg'],
                        ['mockup_url' => 'https://files.printful.com/mockup2.jpg'],
                    ],
                ],
            ], 200),
        ]);

        $hat = Hat::factory()->create(['image_url' => 'https://example.com/old.png']);

        $response = $this->getJson("/api/mockup-tasks/abc123?hat_id={$hat->id}");

        $response->assertStatus(200);
        $response->assertJson(['status' => 'completed']);

        $hat->refresh();

        $this->assertEquals(
            ['https://files.printful.com/mockup1.jpg', 'https://files.printful.com/mockup2.jpg'],
            $hat->mockup_urls
        );
        $this->assertEquals('https://files.printful.com/mockup1.jpg', $hat->image_url);
    }

    public function test_mockup_endpoint_returns_422_and_leaves_hat_unchanged_on_printful_failure(): void
    {
        config(['services.printful.key' => 'test-key']);

        Http::fake([
            'api.printful.com/mockup-generator/create-task/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $hat = Hat::factory()->create([
            'printful_product_id' => null,
            'printful_variant_id' => null,
            'design_file_id' => null,
        ]);
        $designFile = DesignFile::factory()->create();

        $response = $this->postJson("/api/hats/{$hat->id}/mockup", [
            'printful_product_id' => 206,
            'printful_variant_id' => 4011,
            'design_file_id' => $designFile->id,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error'));

        $this->assertDatabaseHas('hats', [
            'id' => $hat->id,
            'printful_product_id' => null,
            'printful_variant_id' => null,
            'design_file_id' => null,
        ]);
    }

    public function test_supplier_order_creates_draft_order_without_confirm_param(): void
    {
        config(['services.printful.key' => 'test-key']);

        Http::fake([
            'api.printful.com/orders' => Http::response([
                'result' => ['id' => 999, 'status' => 'draft'],
            ], 200),
        ]);

        $designFile = DesignFile::factory()->create();
        $hat = Hat::factory()->create([
            'printful_variant_id' => 4011,
            'design_file_id' => $designFile->id,
        ]);

        $response = $this->postJson("/api/hats/{$hat->id}/supplier-order", [
            'recipient' => [
                'name' => 'Amir Lifshitz',
                'address1' => '1 Rothschild Blvd',
                'city' => 'Tel Aviv',
                'country_code' => 'IL',
                'zip' => '1234567',
            ],
            'quantity' => 2,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => 999, 'status' => 'draft']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.printful.com/orders'
                && ($body['recipient']['name'] ?? null) === 'Amir Lifshitz'
                && ($body['items'][0]['variant_id'] ?? null) === 4011
                && ($body['items'][0]['quantity'] ?? null) === 2
                && ! array_key_exists('confirm', $body)
                && ! array_key_exists('confirm', $body['items'][0] ?? []);
        });
    }

    public function test_supplier_order_without_design_returns_422(): void
    {
        $hat = Hat::factory()->create([
            'printful_variant_id' => null,
            'design_file_id' => null,
        ]);

        $response = $this->postJson("/api/hats/{$hat->id}/supplier-order", [
            'recipient' => [
                'name' => 'Amir Lifshitz',
                'address1' => '1 Rothschild Blvd',
                'city' => 'Tel Aviv',
                'country_code' => 'IL',
                'zip' => '1234567',
            ],
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error'));
    }

    public function test_printful_products_endpoint_falls_back_without_api_key(): void
    {
        config(['services.printful.key' => null]);

        Http::fake(); // Should never be called.

        $response = $this->getJson('/api/printful/products');

        $response->assertStatus(200);
        $response->assertJson(['live' => false]);
        $response->assertJsonFragment(['title' => 'Snapback Cap']);
        $response->assertJsonFragment(['title' => 'Dad Hat']);
        $response->assertJsonFragment(['title' => 'Trucker Cap']);

        Http::assertNothingSent();
    }
}
