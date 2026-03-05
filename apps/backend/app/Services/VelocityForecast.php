<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Immutable value object carrying the output of a velocity forecast.
 */
final readonly class VelocityForecast
{
    /**
     * @param  list<int>  $weeklySeries  Historical effort totals per week, oldest → newest
     */
    public function __construct(
        public float $velocityEma,
        public float $velocityStdDev,
        public int $upcomingEffort,
        public float $capacityUpperBound,
        public float $probabilityOfSuccess,
        public bool $burnoutRisk,
        public int $weeksAnalysed,
        public array $weeklySeries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'velocity_ema' => round($this->velocityEma, 2),
            'velocity_std_dev' => round($this->velocityStdDev, 2),
            'upcoming_effort' => $this->upcomingEffort,
            'capacity_upper_bound' => round($this->capacityUpperBound, 2),
            'probability_of_success' => round($this->probabilityOfSuccess, 4),
            'burnout_risk' => $this->burnoutRisk,
            'weeks_analysed' => $this->weeksAnalysed,
            'weekly_series' => $this->weeklySeries,
        ];
    }
}
