<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CognitiveProfile>
 */
final class CognitiveProfileFactory extends Factory
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
            // Default: 100-minute cycle (the canonical ultradian period)
            'dominant_period_seconds' => 6000.0,
            'phase_anchor_at' => CarbonImmutable::now()->subMinutes(30),
            'amplitude' => 2.5,
            'confidence' => 0.7,
            'sample_count' => 50,
            'computed_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Peak is RIGHT NOW — useful for asserting phase === 'peak'.
     */
    public function peakedNow(): static
    {
        return $this->state(fn () => [
            'phase_anchor_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Half a cycle ago — we are now in a trough.
     */
    public function inTrough(): static
    {
        return $this->state(function (array $attrs) {
            $period = $attrs['dominant_period_seconds'] ?? 6000.0;

            return [
                'phase_anchor_at' => CarbonImmutable::now()->subSeconds((int) ($period / 2)),
            ];
        });
    }

    public function stale(): static
    {
        return $this->state(fn () => [
            'computed_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }
}
