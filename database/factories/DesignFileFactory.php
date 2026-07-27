<?php

namespace Database\Factories;

use App\Models\DesignFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DesignFile>
 */
class DesignFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $data = 'fake-design-bytes';

        return [
            'filename' => 'design.png',
            'mime' => 'image/png',
            'data' => $data,
            'byte_size' => strlen($data),
        ];
    }
}
