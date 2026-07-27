<?php

namespace Database\Factories;

use App\Models\Hat;
use App\Services\HatArtService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hat>
 */
class HatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $styles = ['Baseball', 'Snapback', 'Trucker', 'Beanie', 'Bucket'];
        $colors = ['Black', 'Navy', 'Red', 'Olive', 'Charcoal', 'White', 'Khaki'];

        $style = fake()->randomElement($styles);
        $color = fake()->randomElement($colors);

        return [
            'name' => "{$color} {$style} Hat",
            'color' => $color,
            'style' => $style,
            'size' => fake()->randomElement(\App\Models\Hat::SIZES),
            'description' => fake()->sentence(12),
            // Never blank: image_url is NOT NULL and required by the API.
            'image_url' => HatArtService::urlFor($style, $color),
            'price' => fake()->randomFloat(2, 15, 60),
        ];
    }
}
