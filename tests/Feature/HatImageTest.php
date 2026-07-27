<?php

namespace Tests\Feature;

use App\Models\Hat;
use App\Services\HatArtService;
use Database\Seeders\HatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every hat product must carry a picture of the hat — not nullable in the
 * database, not blank through the API, not missing in the UI.
 */
class HatImageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunset Trucker',
            'color' => 'Orange',
            'style' => 'Trucker',
            'price' => 49.99,
            'image_url' => 'https://example.com/hat.png',
        ], $overrides);
    }

    public function test_store_requires_an_image(): void
    {
        $payload = $this->payload();
        unset($payload['image_url']);

        $response = $this->postJson('/api/hats', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_url']);
    }

    public function test_store_rejects_a_null_image(): void
    {
        $response = $this->postJson('/api/hats', $this->payload(['image_url' => null]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_url']);
    }

    public function test_store_rejects_an_empty_image(): void
    {
        $response = $this->postJson('/api/hats', $this->payload(['image_url' => '']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_url']);
    }

    public function test_store_rejects_a_whitespace_only_image(): void
    {
        $response = $this->postJson('/api/hats', $this->payload(['image_url' => "   \t "]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_url']);
    }

    public function test_store_rejects_a_non_url_image(): void
    {
        $response = $this->postJson('/api/hats', $this->payload(['image_url' => 'just some text']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_url']);
    }

    public function test_store_accepts_a_path_served_by_this_app(): void
    {
        $response = $this->postJson('/api/hats', $this->payload([
            'image_url' => '/design-files/12',
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('hats', ['image_url' => '/design-files/12']);
    }

    public function test_store_trims_the_stored_image(): void
    {
        $response = $this->postJson('/api/hats', $this->payload([
            'image_url' => '  https://example.com/hat.png  ',
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('hats', ['image_url' => 'https://example.com/hat.png']);
    }

    public function test_update_cannot_blank_out_an_existing_image(): void
    {
        $hat = Hat::factory()->create(['image_url' => 'https://example.com/hat.png']);

        foreach ([null, '', '  '] as $blank) {
            $response = $this->putJson("/api/hats/{$hat->id}", ['image_url' => $blank]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['image_url']);
        }

        $this->assertSame('https://example.com/hat.png', $hat->fresh()->image_url);
    }

    public function test_update_can_replace_the_image(): void
    {
        $hat = Hat::factory()->create(['image_url' => 'https://example.com/old.png']);

        $response = $this->putJson("/api/hats/{$hat->id}", [
            'image_url' => 'https://example.com/new.png',
        ]);

        $response->assertStatus(200);
        $this->assertSame('https://example.com/new.png', $hat->fresh()->image_url);
    }

    public function test_the_column_is_not_nullable(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('hats')->insert([
            'name' => 'Naked Cap',
            'color' => 'Red',
            'style' => 'Baseball',
            'size' => 'Universal',
            'price' => 10,
            'image_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_factory_always_produces_an_image(): void
    {
        foreach (Hat::factory()->count(15)->create() as $hat) {
            $this->assertNotEmpty(trim((string) $hat->image_url));
        }
    }

    public function test_seeded_hats_all_have_an_image(): void
    {
        $this->seed(HatSeeder::class);

        $hats = Hat::all();

        $this->assertGreaterThan(0, $hats->count());

        foreach ($hats as $hat) {
            $this->assertNotEmpty(trim((string) $hat->image_url));
            $this->assertStringStartsWith('/hat-art/', $hat->image_url);
        }
    }

    public function test_generated_art_is_served_as_an_svg(): void
    {
        $response = $this->get(HatArtService::urlFor('Baseball', 'Red'));

        $response->assertStatus(200);
        $this->assertStringContainsString('image/svg+xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringContainsString('#e03131', $response->getContent());
    }

    public function test_generated_art_survives_a_junk_style_and_color(): void
    {
        $response = $this->get('/hat-art/Fedora?color=not-a-color');

        $response->assertStatus(200);
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_the_dashboard_renders_every_hat_image(): void
    {
        $hat = Hat::factory()->create(['image_url' => 'https://example.com/visible-hat.png']);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('https://example.com/visible-hat.png', escape: false);
    }
}
