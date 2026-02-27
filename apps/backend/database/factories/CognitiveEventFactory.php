<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CognitiveEvent>
 */
final class CognitiveEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'item_id' => Item::factory(),
            'event_type' => fake()->randomElement(['started', 'completed', 'cancelled']),
            'cognitive_load_score' => fake()->numberBetween(1, 10),
            'recorded_at' => now(),
        ];
    }

    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => 'started',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => 'completed',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => 'cancelled',
        ]);
    }

    public function withScore(int $score): static
    {
        return $this->state(fn (array $attributes) => [
            'cognitive_load_score' => $score,
        ]);
    }

    public function at(\DateTimeInterface|string $timestamp): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_at' => $timestamp,
        ]);
    }
}
