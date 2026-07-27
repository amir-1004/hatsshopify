<?php

namespace Database\Factories;

use App\Models\Hat;
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
            'image_url' => fake()->imageUrl(640, 480, 'fashion'),
            'price' => fake()->randomFloat(2, 15, 60),
        ];
    }
}
