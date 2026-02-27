<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CognitiveEventType;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            'item_id' => null,
            'event_type' => CognitiveEventType::Completed->value,
            'cognitive_load_score' => fake()->numberBetween(1, 10),
            'occurred_at' => CarbonImmutable::now()->subMinutes(fake()->numberBetween(1, 20160)),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['event_type' => CognitiveEventType::Completed->value]);
    }

    public function started(): static
    {
        return $this->state(fn () => ['event_type' => CognitiveEventType::Started->value]);
    }

    public function at(CarbonImmutable $moment): static
    {
        return $this->state(fn () => ['occurred_at' => $moment]);
    }

    public function withLoad(int $score): static
    {
        return $this->state(fn () => ['cognitive_load_score' => $score]);
    }
}
