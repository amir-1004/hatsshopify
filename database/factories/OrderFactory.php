<?php

namespace Database\Factories;

use App\Models\Hat;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);
        $totalPrice = fake()->randomFloat(2, 15, 200);

        return [
            'shopify_order_id' => (string) fake()->unique()->numberBetween(1000000000, 9999999999),
            'hat_id' => Hat::factory(),
            'customer_email' => fake()->safeEmail(),
            'customer_name' => fake()->name(),
            'order_number' => '#'.fake()->unique()->numberBetween(1000, 9999),
            'total_price' => $totalPrice,
            'currency' => 'USD',
            'status' => fake()->randomElement(['pending', 'paid', 'refunded']),
            'quantity' => $quantity,
            'order_data' => ['note' => fake()->sentence()],
        ];
    }
}
