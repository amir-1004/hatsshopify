<?php

namespace Database\Seeders;

use App\Models\Hat;
use App\Services\HatArtService;
use Illuminate\Database\Seeder;

class HatSeeder extends Seeder
{
    /**
     * Seed the hats table.
     *
     * Uses firstOrCreate matched on name so this is safe to run on every
     * deploy without creating duplicates or crashing on a second run.
     */
    public function run(): void
    {
        $hats = [
            [
                'name' => 'Classic Red Cap',
                'color' => 'Red',
                'style' => 'Baseball',
                'size' => 'Universal',
                'description' => 'A timeless red baseball cap with a curved brim and adjustable strap. Perfect for everyday wear, rain or shine.',
                'price' => 89.90,
            ],
            [
                'name' => 'Black Trucker Hat',
                'color' => 'Black',
                'style' => 'Trucker',
                'size' => 'L',
                'description' => 'Breathable mesh-back trucker hat in classic black. Bold, laid-back, and built for warm days on the move.',
                'price' => 99.90,
            ],
            [
                'name' => 'Ocean Snapback',
                'color' => 'Blue',
                'style' => 'Snapback',
                'size' => 'Universal',
                'description' => 'A crisp blue snapback with a flat brim and structured crown. Street-ready style with an adjustable snap fit.',
                'price' => 109.00,
            ],
            [
                'name' => 'Forest Beanie',
                'color' => 'Green',
                'style' => 'Beanie',
                'size' => 'S',
                'description' => 'Soft knit beanie in a deep forest green. Snug, warm, and effortless for cold-weather layering.',
                'price' => 69.90,
            ],
            [
                'name' => 'Desert Bucket Hat',
                'color' => 'Beige',
                'style' => 'Bucket',
                'size' => 'M',
                'description' => 'Lightweight beige bucket hat with a wide brim for sun protection. A relaxed staple for warm-weather outings.',
                'price' => 79.90,
            ],
        ];

        foreach ($hats as $hat) {
            // Every hat ships with a picture — generated art matching its
            // own style and color, so image_url is never blank.
            $hat['image_url'] = HatArtService::urlFor($hat['style'], $hat['color']);

            Hat::firstOrCreate(['name' => $hat['name']], $hat);
        }
    }
}
