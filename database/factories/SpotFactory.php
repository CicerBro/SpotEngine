<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spot>
 */
class SpotFactory extends Factory
{
    public function definition(): array
    {
        $categories = ['01', '02', '03', '04'];
        $category = fake()->randomElement($categories);

        return [
            'message_id' => '<' . fake()->uuid() . '@news.example.com>',
            'poster' => fake()->name() . ' <' . fake()->email() . '>',
            'poster_key_id' => null,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'tag' => fake()->optional()->word(),
            'website' => fake()->optional()->url(),
            'category_code' => $category,
            'subcategories' => [],
            'file_size' => fake()->numberBetween(100_000_000, 50_000_000_000),
            'image_segments' => [],
            'nzb_segments' => [
                fake()->uuid() . '@news.example.com',
                fake()->uuid() . '@news.example.com',
            ],
            'spot_posted_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'xml_signature' => null,
            'is_verified' => false,
        ];
    }

    public function inCategory(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'category_code' => $code,
        ]);
    }

    public function withImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'image_segments' => [$segment = fake()->uuid() . '@news.example.com'],
        ]);
    }
}
