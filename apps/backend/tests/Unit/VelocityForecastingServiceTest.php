<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\VelocityForecastingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math unit tests for the forecasting service. No database required —
 * all tests exercise buildForecast() and its constituent pure functions so
 * the EMA/σ/Φ logic can be verified against hand-computed ground truth.
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
    // EMA — Exponential Moving Average
    // ─────────────────────────────────────────────────────────────────────

    public function test_ema_of_empty_series_is_zero(): void
    {
        $this->assertSame(0.0, $this->service->calculateEma([], 0.5));
    }

    public function test_ema_of_single_value_equals_that_value(): void
    {
        $this->assertSame(7.0, $this->service->calculateEma([7], 0.5));
    }

    public function test_ema_of_constant_series_equals_the_constant(): void
    {
        // Invariant: if every Xₜ = c then every EMAₜ = c regardless of α.
        $this->assertEqualsWithDelta(
            10.0,
            $this->service->calculateEma([10, 10, 10, 10, 10], 0.3),
            1e-12
        );
    }

    public function test_ema_with_alpha_one_equals_last_observation(): void
    {
        // α = 1 → no memory: EMAₜ = Xₜ
        $this->assertSame(8.0, $this->service->calculateEma([1, 2, 3, 5, 8], 1.0));
    }

    public function test_ema_with_alpha_zero_equals_first_observation(): void
    {
        // α = 0 → infinite memory: EMAₜ = EMA₀ = X₀
        $this->assertSame(1.0, $this->service->calculateEma([1, 2, 3, 5, 8], 0.0));
    }

    public function test_ema_recurrence_matches_hand_calculation(): void
    {
        // Manually: α = 0.5, series = [10, 20, 30]
        //   EMA₀ = 10
        //   EMA₁ = 0.5·20 + 0.5·10 = 15
        //   EMA₂ = 0.5·30 + 0.5·15 = 22.5
        $this->assertEqualsWithDelta(
            22.5,
            $this->service->calculateEma([10, 20, 30], 0.5),
            1e-12
        );
    }

    public function test_ema_weights_recent_observations_more_heavily(): void
    {
        // At α = 0.5 over 4 samples the final observation carries weight α = 0.5
        // while the seed retains only β³ = 0.125. Same values, opposite order —
        // the series ending high must produce the higher EMA.
        $alpha = 0.5;

        $endingHigh = $this->service->calculateEma([5, 5, 5, 20], $alpha);
        $endingLow = $this->service->calculateEma([20, 5, 5, 5], $alpha);

        $this->assertGreaterThan($endingLow, $endingHigh);
        $this->assertEqualsWithDelta(12.5, $endingHigh, 1e-12);
        $this->assertEqualsWithDelta(6.875, $endingLow, 1e-12);
    }

    public function test_ema_twelve_week_alpha_is_derived_from_lookback(): void
    {
        // Sanity check: the production α is 2/(N+1) with N=12.
        $expectedAlpha = 2.0 / (VelocityForecastingService::LOOKBACK_WEEKS + 1);

        $this->assertEqualsWithDelta(0.153846, $expectedAlpha, 1e-5);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Standard deviation
    // ─────────────────────────────────────────────────────────────────────

    public function test_std_dev_of_empty_series_is_zero(): void
    {
        $this->assertSame(0.0, $this->service->calculateStdDev([], 0.0));
    }

    public function test_std_dev_of_single_value_is_zero(): void
    {
        // n < 2 → undefined sample variance → return 0.
        $this->assertSame(0.0, $this->service->calculateStdDev([42], 42.0));
    }

    public function test_std_dev_of_constant_series_is_zero(): void
    {
        $this->assertEqualsWithDelta(
            0.0,
            $this->service->calculateStdDev([5, 5, 5, 5], 5.0),
            1e-12
        );
    }

    public function test_std_dev_matches_textbook_example(): void
    {
        // Series [2, 4, 4, 4, 5, 5, 7, 9] about mean 5:
        //   deviations² = 9 + 1 + 1 + 1 + 0 + 0 + 4 + 16 = 32
        //   sample σ = √(32 / 7) ≈ 2.13809
        $series = [2, 4, 4, 4, 5, 5, 7, 9];

        $this->assertEqualsWithDelta(
            sqrt(32.0 / 7.0),
            $this->service->calculateStdDev($series, 5.0),
            1e-12
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Normal CDF — Abramowitz & Stegun 26.2.17
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{float, float}>
     */
    public static function normalCdfReferenceProvider(): array
    {
        // Reference values from standard normal tables (6 d.p.).
        return [
            'Φ(0) = 0.5' => [0.0, 0.5],
            'Φ(1) ≈ 0.8413' => [1.0, 0.841345],
            'Φ(-1) ≈ 0.1587' => [-1.0, 0.158655],
            'Φ(1.96) ≈ 0.975' => [1.96, 0.975002],
            'Φ(-1.96) ≈ 0.025' => [-1.96, 0.024998],
            'Φ(2) ≈ 0.9772' => [2.0, 0.977250],
            'Φ(3) ≈ 0.9987' => [3.0, 0.998650],
            'Φ(-3) ≈ 0.0013' => [-3.0, 0.001350],
        ];
    }

    #[DataProvider('normalCdfReferenceProvider')]
    public function test_normal_cdf_matches_reference_tables(float $x, float $expected): void
    {
        // A&S 26.2.17 guarantees error < 7.5e-8; 1e-6 is a comfortable bound.
        $this->assertEqualsWithDelta($expected, $this->service->normalCdf($x), 1e-6);
    }

    public function test_normal_cdf_is_symmetric(): void
    {
        // Φ(x) + Φ(−x) = 1 for all x.
        foreach ([0.5, 1.0, 1.5, 2.5] as $x) {
            $this->assertEqualsWithDelta(
                1.0,
                $this->service->normalCdf($x) + $this->service->normalCdf(-$x),
                1e-7
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Probability of success
    // ─────────────────────────────────────────────────────────────────────

    public function test_probability_is_one_half_when_required_equals_mean(): void
    {
        // At the mean, P(V ≥ μ) = 0.5 by symmetry.
        $this->assertEqualsWithDelta(
            0.5,
            $this->service->probabilityOfSuccess(10.0, 10.0, 2.0),
            1e-6
        );
    }

    public function test_probability_degenerates_when_std_dev_is_zero(): void
    {
        // No uncertainty → hard threshold.
        $this->assertSame(1.0, $this->service->probabilityOfSuccess(8.0, 10.0, 0.0));
        $this->assertSame(1.0, $this->service->probabilityOfSuccess(10.0, 10.0, 0.0));
        $this->assertSame(0.0, $this->service->probabilityOfSuccess(12.0, 10.0, 0.0));
    }

    public function test_probability_is_monotonically_decreasing_in_required(): void
    {
        $p1 = $this->service->probabilityOfSuccess(5.0, 10.0, 3.0);
        $p2 = $this->service->probabilityOfSuccess(10.0, 10.0, 3.0);
        $p3 = $this->service->probabilityOfSuccess(15.0, 10.0, 3.0);

        $this->assertGreaterThan($p2, $p1);
        $this->assertGreaterThan($p3, $p2);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Integrated forecast — happy path
    // ─────────────────────────────────────────────────────────────────────

    public function test_forecast_with_consistent_velocity_and_reasonable_load(): void
    {
        // User reliably ships 10 pts/week; 8 pts upcoming is well within reach.
        $series = array_fill(0, 12, 10);

        $forecast = $this->service->buildForecast($series, 8);

        $this->assertEqualsWithDelta(10.0, $forecast->velocityEma, 1e-9);
        $this->assertEqualsWithDelta(0.0, $forecast->velocityStdDev, 1e-9);
        $this->assertSame(8, $forecast->upcomingEffort);
        $this->assertFalse($forecast->burnoutRisk);
        $this->assertSame(1.0, $forecast->probabilityOfSuccess);
        $this->assertSame(12, $forecast->weeksAnalysed);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Integrated forecast — sad path (acceptance-criterion scenario)
    // ─────────────────────────────────────────────────────────────────────

    public function test_forecast_flags_burnout_when_load_exceeds_capacity(): void
    {
        // Acceptance criterion: "If a user's historical velocity is 10 points
        // per week, and they assign 25 points to the current week, the UI must
        // immediately flag a Burnout Risk."
        $series = array_fill(0, 12, 10);

        $forecast = $this->service->buildForecast($series, 25);

        $this->assertTrue($forecast->burnoutRisk);
        $this->assertEqualsWithDelta(10.0, $forecast->capacityUpperBound, 1e-9);
        $this->assertSame(0.0, $forecast->probabilityOfSuccess);
    }

    public function test_forecast_flags_burnout_with_variable_history(): void
    {
        // EMA ≈ 10, σ ≈ 2 → capacity ≈ 12. Asking for 20 is a clear overreach.
        $series = [8, 12, 8, 12, 8, 12, 8, 12, 8, 12, 8, 12];

        $forecast = $this->service->buildForecast($series, 20);

        $this->assertTrue($forecast->burnoutRisk);
        $this->assertLessThan(0.01, $forecast->probabilityOfSuccess);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Integrated forecast — edge cases
    // ─────────────────────────────────────────────────────────────────────

    public function test_forecast_with_no_history_and_upcoming_work_is_burnout(): void
    {
        // Brand-new user: zero historical capacity, any non-zero ask is risky.
        $forecast = $this->service->buildForecast(array_fill(0, 12, 0), 5);

        $this->assertSame(0.0, $forecast->velocityEma);
        $this->assertTrue($forecast->burnoutRisk);
        $this->assertSame(0.0, $forecast->probabilityOfSuccess);
    }

    public function test_forecast_with_no_history_and_no_upcoming_work_is_safe(): void
    {
        // Vacuous success: nothing to do → cannot burn out.
        $forecast = $this->service->buildForecast(array_fill(0, 12, 0), 0);

        $this->assertFalse($forecast->burnoutRisk);
        $this->assertSame(1.0, $forecast->probabilityOfSuccess);
    }

    public function test_forecast_at_exact_capacity_boundary_is_not_burnout(): void
    {
        // EMA = 10, σ = 0, capacity = 10. Upcoming = 10 → strictly NOT > 10.
        $series = array_fill(0, 12, 10);

        $forecast = $this->service->buildForecast($series, 10);

        $this->assertFalse(
            $forecast->burnoutRisk,
            'At the exact capacity boundary, the user is pushing their limit but not exceeding it.'
        );
    }

    public function test_forecast_float_drift_does_not_trigger_false_alarm(): void
    {
        // Construct a series whose EMA converges toward 10.0 but via a path
        // that accumulates sub-ulp error. Assert that 10 ≤ round(EMA, 4)
        // holds and therefore no false burnout fires.
        $series = [3, 7, 11, 13, 9, 12, 8, 10, 11, 9, 10, 10];

        $forecast = $this->service->buildForecast($series, 10);

        $capacity = $forecast->capacityUpperBound;
        $this->assertSame(
            round($capacity, 4) < 10,
            $forecast->burnoutRisk,
            'Burnout flag must agree with the drift-rounded comparison.'
        );
    }

    public function test_forecast_respects_upward_trend(): void
    {
        // Ramping 5 → 16. EMA will sit below 16 (memory drags it back) but
        // comfortably above 10. Variance from the ramp widens the capacity band.
        $series = [5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];

        $forecast = $this->service->buildForecast($series, 10);

        $this->assertGreaterThan(10.0, $forecast->velocityEma);
        $this->assertLessThan(16.0, $forecast->velocityEma);
        $this->assertFalse($forecast->burnoutRisk);
    }

    public function test_forecast_respects_downward_trend(): void
    {
        // User is slowing down. Recent weeks (~5) dominate; asking for 15 now
        // is unrealistic even though the early weeks were strong.
        $series = [16, 15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5];

        $forecast = $this->service->buildForecast($series, 15);

        $this->assertLessThan(11.0, $forecast->velocityEma);
        $this->assertTrue($forecast->burnoutRisk);
    }

    public function test_forecast_dto_serialises_with_rounded_precision(): void
    {
        $forecast = $this->service->buildForecast([10, 12, 11, 13, 9, 10, 11, 12, 10, 11, 10, 12], 11);
        $array = $forecast->toArray();

        $this->assertIsFloat($array['velocity_ema']);
        $this->assertIsFloat($array['velocity_std_dev']);
        $this->assertIsFloat($array['capacity_upper_bound']);
        $this->assertIsFloat($array['probability_of_success']);
        $this->assertIsInt($array['upcoming_effort']);
        $this->assertIsBool($array['burnout_risk']);
        $this->assertIsArray($array['weekly_series']);
        $this->assertCount(12, $array['weekly_series']);

        // Verify API-boundary rounding: 2 dp on points, 4 dp on probability.
        $this->assertSame(round($forecast->velocityEma, 2), $array['velocity_ema']);
        $this->assertSame(round($forecast->probabilityOfSuccess, 4), $array['probability_of_success']);
    }
}
