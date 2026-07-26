<?php

namespace Tests\Feature;

use App\Models\Hat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_hats_newest_first(): void
    {
        Hat::factory()->count(20)->sequence(
            fn ($sequence) => ['created_at' => now()->subMinutes($sequence->index)]
        )->create();

        $response = $this->getJson('/api/hats');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'links', 'total', 'current_page', 'per_page']);
        $this->assertCount(15, $response->json('data'));
        $this->assertEquals(20, $response->json('total'));
        $this->assertEquals(15, $response->json('per_page'));

        // Newest first: the first row should be the most recently created hat.
        $newest = Hat::orderByDesc('created_at')->first();
        $this->assertEquals($newest->id, $response->json('data.0.id'));
    }

    public function test_store_creates_a_hat(): void
    {
        $payload = [
            'name' => 'Sunset Trucker',
            'color' => 'Orange',
            'style' => 'Trucker',
            'description' => 'A warm-toned trucker hat.',
            'image_url' => 'https://example.com/hat.png',
            'price' => 49.99,
        ];

        $response = $this->postJson('/api/hats', $payload);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Sunset Trucker']);

        $this->assertDatabaseHas('hats', [
            'name' => 'Sunset Trucker',
            'color' => 'Orange',
            'style' => 'Trucker',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $payload = [
            'color' => 'Orange',
            'style' => 'Trucker',
            'price' => 49.99,
        ];

        $response = $this->postJson('/api/hats', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_negative_price(): void
    {
        $payload = [
            'name' => 'Sunset Trucker',
            'color' => 'Orange',
            'style' => 'Trucker',
            'price' => -10,
        ];

        $response = $this->postJson('/api/hats', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    public function test_show_returns_a_hat(): void
    {
        $hat = Hat::factory()->create(['name' => 'Classic Red Cap']);

        $response = $this->getJson("/api/hats/{$hat->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Classic Red Cap']);
    }

    public function test_show_returns_404_for_missing_hat(): void
    {
        $response = $this->getJson('/api/hats/999999');

        $response->assertStatus(404);
    }

    public function test_update_applies_a_partial_change(): void
    {
        $hat = Hat::factory()->create([
            'name' => 'Classic Red Cap',
            'color' => 'Red',
            'price' => 89.90,
        ]);

        $response = $this->putJson("/api/hats/{$hat->id}", ['price' => 74.50]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Classic Red Cap']);

        $this->assertEquals(74.50, (float) $hat->fresh()->price);
        $this->assertEquals('Red', $hat->fresh()->color);
    }

    public function test_destroy_deletes_the_hat(): void
    {
        $hat = Hat::factory()->create();

        $response = $this->deleteJson("/api/hats/{$hat->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('hats', ['id' => $hat->id]);
    }
}
