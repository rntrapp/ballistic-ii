<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\VelocityForecastingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure maths tests — no database. Exercises computeMetrics() directly with
 * hand-crafted weekly series so every branch of the EMA / variance / CDF
 * pipeline is provably correct before integration tests touch Eloquent.
 */
final class VelocityForecastingServiceTest extends TestCase
{
    private VelocityForecastingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VelocityForecastingService;
    }

    // ─────────────────────────────────────────────────────────────────────
    // EMA correctness
    // ─────────────────────────────────────────────────────────────────────

    public function test_ema_of_constant_series_equals_the_constant(): void
    {
        // α = 0.4. A flat series must converge to its only value with zero
        // variance. This is the canonical drift-guard test: if fixed-point
        // arithmetic were leaking we'd see 9.9999… here.
        $result = $this->service->computeMetrics([10, 10, 10, 10, 10, 10], 0);

        $this->assertSame(10.0, $result['velocity']);
        $this->assertSame(0.0, $result['std_dev']);
    }

    public function test_ema_with_single_observation_returns_that_observation(): void
    {
        $result = $this->service->computeMetrics([7], 0);

        $this->assertSame(7.0, $result['velocity']);
        $this->assertSame(0.0, $result['std_dev']);
        $this->assertSame(1, $result['sample_weeks']);
    }

    public function test_ema_recursion_matches_hand_calculation(): void
    {
        // Series: 10, 20, 30, 40 with α = 0.4 (N = 4)
        //   EMA₀ = 10
        //   EMA₁ = 0.4·20 + 0.6·10 = 14
        //   EMA₂ = 0.4·30 + 0.6·14 = 20.4
        //   EMA₃ = 0.4·40 + 0.6·20.4 = 28.24
        $result = $this->service->computeMetrics([10, 20, 30, 40], 0);

        $this->assertSame(28.24, $result['velocity']);
    }

    public function test_ema_weights_recent_weeks_more_than_old_weeks(): void
    {
        // Same values, opposite chronology — recent spike should dominate.
        $recentSpike = $this->service->computeMetrics([5, 5, 5, 20], 0);
        $oldSpike = $this->service->computeMetrics([20, 5, 5, 5], 0);

        $this->assertGreaterThan($oldSpike['velocity'], $recentSpike['velocity']);
    }

    public function test_zero_weeks_pull_velocity_down(): void
    {
        // A user who takes two weeks off should see their velocity drop.
        $steady = $this->service->computeMetrics([10, 10, 10, 10], 0);
        $withGap = $this->service->computeMetrics([10, 10, 0, 0], 0);

        $this->assertLessThan($steady['velocity'], $withGap['velocity']);
    }

    public function test_all_zero_history_yields_zero_velocity(): void
    {
        $result = $this->service->computeMetrics([0, 0, 0, 0, 0, 0], 5);

        $this->assertSame(0.0, $result['velocity']);
        $this->assertSame(0.0, $result['std_dev']);
        $this->assertTrue($result['burnout_risk']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Variance / standard deviation
    // ─────────────────────────────────────────────────────────────────────

    public function test_alternating_series_has_positive_variance(): void
    {
        $result = $this->service->computeMetrics([5, 15, 5, 15, 5, 15], 0);

        $this->assertGreaterThan(0.0, $result['std_dev']);
        // Capacity upper bound must strictly exceed the mean when σ > 0.
        $this->assertGreaterThan($result['velocity'], $result['capacity_upper']);
    }

    public function test_capacity_upper_equals_velocity_plus_std_dev(): void
    {
        $result = $this->service->computeMetrics([3, 8, 5, 13, 2, 8], 0);

        $this->assertEqualsWithDelta(
            $result['velocity'] + $result['std_dev'],
            $result['capacity_upper'],
            0.0001
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Burnout gate — the money test from the spec
    // ─────────────────────────────────────────────────────────────────────

    public function test_burnout_triggered_when_load_grossly_exceeds_velocity(): void
    {
        // Spec: "historical velocity 10 pts/week, assign 25 pts → Burnout Risk"
        $result = $this->service->computeMetrics([10, 10, 10, 10, 10, 10], 25);

        $this->assertTrue($result['burnout_risk']);
        $this->assertSame(25, $result['upcoming_load']);
        $this->assertSame(0.0, $result['probability_of_success']);
    }

    public function test_no_burnout_when_load_equals_velocity_exactly(): void
    {
        // Boundary: load == μ, σ == 0. Strict inequality means NOT burnout.
        // This is the floating-point drift guard — a naive float EMA that
        // produced 9.9999… would wrongly flag burnout here.
        $result = $this->service->computeMetrics([10, 10, 10, 10], 10);

        $this->assertFalse($result['burnout_risk']);
    }

    public function test_no_burnout_when_load_is_under_velocity(): void
    {
        $result = $this->service->computeMetrics([10, 10, 10, 10], 8);

        $this->assertFalse($result['burnout_risk']);
        $this->assertSame(1.0, $result['probability_of_success']);
    }

    public function test_burnout_when_load_exceeds_velocity_by_one_with_zero_variance(): void
    {
        // σ = 0 → upper bound = μ. load of μ+1 must trip the gate.
        $result = $this->service->computeMetrics([10, 10, 10, 10], 11);

        $this->assertTrue($result['burnout_risk']);
    }

    public function test_no_burnout_when_load_sits_inside_one_sigma_band(): void
    {
        // Erratic history → wide σ → more headroom before burnout triggers.
        $result = $this->service->computeMetrics([5, 15, 5, 15, 5, 15], 12);

        $this->assertGreaterThan(12, $result['capacity_upper']);
        $this->assertFalse($result['burnout_risk']);
    }

    public function test_zero_load_is_never_burnout(): void
    {
        $result = $this->service->computeMetrics([1, 1, 1], 0);

        $this->assertFalse($result['burnout_risk']);
        $this->assertSame(1.0, $result['probability_of_success']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Probability of success (normal CDF)
    // ─────────────────────────────────────────────────────────────────────

    public function test_probability_is_fifty_percent_when_load_equals_mean(): void
    {
        // With σ > 0, load == μ gives Z = 0 → Φ(0) = 0.5 → P(success) = 0.5
        $result = $this->service->computeMetrics([8, 12, 8, 12, 8, 12], 0);

        // Use the service's own mean as the load target.
        $mean = (int) round($result['velocity']);
        $atMean = $this->service->computeMetrics([8, 12, 8, 12, 8, 12], $mean);

        $this->assertEqualsWithDelta(0.5, $atMean['probability_of_success'], 0.1);
    }

    public function test_probability_decreases_as_load_increases(): void
    {
        $history = [5, 15, 5, 15, 5, 15];

        $light = $this->service->computeMetrics($history, 5);
        $medium = $this->service->computeMetrics($history, 12);
        $heavy = $this->service->computeMetrics($history, 20);

        $this->assertGreaterThan($medium['probability_of_success'], $light['probability_of_success']);
        $this->assertGreaterThan($heavy['probability_of_success'], $medium['probability_of_success']);
    }

    public function test_probability_is_bounded_between_zero_and_one(): void
    {
        $history = [5, 15, 5, 15];

        foreach ([0, 1, 10, 50, 100, 1000] as $load) {
            $p = $this->service->computeMetrics($history, $load)['probability_of_success'];
            $this->assertGreaterThanOrEqual(0.0, $p, "load={$load}");
            $this->assertLessThanOrEqual(1.0, $p, "load={$load}");
        }
    }

    public function test_probability_collapses_to_binary_when_variance_is_zero(): void
    {
        // σ = 0 → step function at μ.
        $under = $this->service->computeMetrics([8, 8, 8], 7);
        $over = $this->service->computeMetrics([8, 8, 8], 9);

        $this->assertSame(1.0, $under['probability_of_success']);
        $this->assertSame(0.0, $over['probability_of_success']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cold-start / edge cases
    // ─────────────────────────────────────────────────────────────────────

    public function test_empty_history_with_zero_load_is_safe(): void
    {
        $result = $this->service->computeMetrics([], 0);

        $this->assertSame(0.0, $result['velocity']);
        $this->assertSame(0, $result['sample_weeks']);
        $this->assertFalse($result['burnout_risk']);
        $this->assertSame(1.0, $result['probability_of_success']);
    }

    public function test_empty_history_with_any_load_is_burnout(): void
    {
        // New user, no track record, scheduling work → flag it.
        $result = $this->service->computeMetrics([], 3);

        $this->assertTrue($result['burnout_risk']);
        $this->assertSame(0.0, $result['probability_of_success']);
    }

    public function test_large_effort_values_do_not_overflow(): void
    {
        // 12 weeks of max-Fibonacci items completed every day = 8 × 7 = 56/week.
        // Integer arithmetic stays in range; result must remain sane.
        $result = $this->service->computeMetrics(array_fill(0, 12, 56), 56);

        $this->assertSame(56.0, $result['velocity']);
        $this->assertFalse($result['burnout_risk']);
    }

    public function test_weekly_history_is_passed_through_unchanged(): void
    {
        $history = [3, 5, 0, 8, 2];
        $result = $this->service->computeMetrics($history, 0);

        $this->assertSame($history, $result['weekly_history']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Parametrised Fibonacci-scale scenarios
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('fibonacciLoadScenarios')]
    public function test_burnout_gate_across_fibonacci_scale(
        array $history,
        int $load,
        bool $expectedBurnout
    ): void {
        $result = $this->service->computeMetrics($history, $load);

        $this->assertSame($expectedBurnout, $result['burnout_risk']);
    }

    /**
     * @return array<string, array{0: list<int>, 1: int, 2: bool}>
     */
    public static function fibonacciLoadScenarios(): array
    {
        return [
            'light steady, light load' => [[2, 2, 2, 2], 2, false],
            'light steady, heavy load' => [[2, 2, 2, 2], 8, true],
            'heavy steady, matching load' => [[8, 8, 8, 8], 8, false],
            'heavy steady, light load' => [[8, 8, 8, 8], 3, false],
            'ramping up, ambitious load' => [[1, 2, 3, 5], 8, true],
            'ramping up, realistic load' => [[1, 2, 3, 5], 5, false],
            'ramping down, optimistic load' => [[8, 5, 3, 2], 8, true],
        ];
    }
}
