<?php

namespace Tests\Feature;

use App\Models\Hat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TryOnTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_try_on_page_loads(): void
    {
        Hat::factory()->create(['name' => 'Classic Red Cap']);

        $response = $this->get('/try-on');

        $response->assertStatus(200);
        $response->assertSee('Classic Red Cap');
    }

    public function test_the_try_on_page_can_preselect_a_hat(): void
    {
        $hat = Hat::factory()->create(['name' => 'Ocean Snapback', 'size' => 'L']);

        $response = $this->get("/try-on/{$hat->id}");

        $response->assertStatus(200);
        $response->assertSee('Ocean Snapback');
    }

    public function test_the_try_on_page_404s_for_an_unknown_hat(): void
    {
        $this->get('/try-on/999999')->assertStatus(404);
    }

    public function test_it_recommends_a_size_from_pixel_measurements(): void
    {
        $response = $this->postJson('/api/try-on/recommend', [
            'interpupillary_px' => 63,
            'face_width_px' => 140,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'head_circumference_cm',
            'recommended_size',
            'size_range_cm' => ['min', 'max'],
            'available_sizes',
            'note',
        ]);

        $this->assertContains($response->json('recommended_size'), Hat::SIZES);
        $this->assertGreaterThan(50, $response->json('head_circumference_cm'));
    }

    public function test_it_accepts_a_known_circumference_directly(): void
    {
        $response = $this->postJson('/api/try-on/recommend', [
            'head_circumference_cm' => 59.0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['recommended_size' => 'L']);
    }

    public function test_it_reports_whether_the_selected_hat_fits(): void
    {
        $hat = Hat::factory()->create(['size' => 'XS']);

        $response = $this->postJson('/api/try-on/recommend', [
            'head_circumference_cm' => 61.5,
            'hat_id' => $hat->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['hat_fits' => false]);
        $response->assertJsonFragment(['recommended_size' => 'XL']);
    }

    public function test_it_confirms_a_hat_that_does_fit(): void
    {
        $hat = Hat::factory()->create(['size' => 'M']);

        $response = $this->postJson('/api/try-on/recommend', [
            'head_circumference_cm' => 57.5,
            'hat_id' => $hat->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['hat_fits' => true]);
    }

    public function test_it_requires_a_measurement(): void
    {
        $response = $this->postJson('/api/try-on/recommend', []);

        $response->assertStatus(422);
    }

    public function test_it_rejects_a_nonsense_measurement(): void
    {
        $response = $this->postJson('/api/try-on/recommend', [
            'interpupillary_px' => 0,
            'face_width_px' => 140,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['interpupillary_px']);
    }

    public function test_it_rejects_a_measurement_that_is_not_a_head(): void
    {
        // A "face" ten times wider than the eyes are apart is not a face.
        $response = $this->postJson('/api/try-on/recommend', [
            'interpupillary_px' => 20,
            'face_width_px' => 900,
        ]);

        $response->assertStatus(422);
    }

    public function test_it_rejects_an_unknown_hat(): void
    {
        $response = $this->postJson('/api/try-on/recommend', [
            'head_circumference_cm' => 57.0,
            'hat_id' => 999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hat_id']);
    }
}
