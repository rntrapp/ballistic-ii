<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
final class ItemFactory extends Factory
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
            'project_id' => Project::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional(0.7)->paragraph(),
            'status' => fake()->randomElement(['todo', 'doing', 'done', 'wontdo']),
            'position' => fake()->numberBetween(0, 100),
            'scheduled_date' => null,
            'due_date' => null,
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
        ];
    }

    public function inbox(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => null,
        ]);
    }

    public function todo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'todo',
        ]);
    }

    public function doing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'doing',
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
            'completed_at' => now(),
        ]);
    }

    public function wontdo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'wontdo',
        ]);
    }

    public function scheduled(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_date' => $date ?? fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
        ]);
    }

    public function withDueDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date ?? fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
        ]);
    }

    public function recurring(string $rule = 'FREQ=DAILY'): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence_rule' => $rule,
        ]);
    }

    /**
     * Create an item that is overdue.
     */
    public function overdue(int $daysAgo = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => now()->subDays($daysAgo)->toDateString(),
            'status' => 'todo',
        ]);
    }

    /**
     * Create an item scheduled for the future.
     */
    public function futureScheduled(int $daysAhead = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_date' => now()->addDays($daysAhead)->toDateString(),
        ]);
    }

    public function withCognitiveLoad(int $score): static
    {
        return $this->state(fn (array $attributes) => [
            'cognitive_load_score' => $score,
        ]);
    }
}
