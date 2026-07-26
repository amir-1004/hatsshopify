<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected string $endpoint = '/api/hats/generate-description';

    protected function payload(): array
    {
        return [
            'name' => 'Sunset Trucker',
            'color' => 'Orange',
            'style' => 'Trucker',
        ];
    }

    public function test_successful_generation_returns_description(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-haiku-4-5']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Bold orange trucker hat built for sunny days.'],
                ],
            ], 200),
        ]);

        $response = $this->postJson($this->endpoint, $this->payload());

        $response->assertStatus(200);
        $response->assertJson([
            'description' => 'Bold orange trucker hat built for sunny days.',
            'error' => null,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request['model'] === 'claude-haiku-4-5'
                && $request['max_tokens'] === 500;
        });
    }

    public function test_anthropic_error_returns_null_description_with_error_flag(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $response = $this->postJson($this->endpoint, $this->payload());

        $response->assertStatus(200);
        $response->assertJson(['description' => null]);
        $this->assertNotEmpty($response->json('error'));
    }

    public function test_missing_api_key_returns_null_description_with_error_flag(): void
    {
        config(['services.anthropic.key' => null]);

        Http::fake(); // Should never be called, but guards against accidental real requests.

        $response = $this->postJson($this->endpoint, $this->payload());

        $response->assertStatus(200);
        $response->assertJson(['description' => null]);
        $this->assertNotEmpty($response->json('error'));

        Http::assertNothingSent();
    }

    public function test_generate_description_requires_name_color_and_style(): void
    {
        $response = $this->postJson($this->endpoint, []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'color', 'style']);
    }
}
