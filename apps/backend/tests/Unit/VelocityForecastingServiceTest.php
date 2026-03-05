<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EffortScore;
use App\Models\Item;
use App\Models\User;
use App\Services\VelocityForecastingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the mathematical correctness of the EMA implementation and verifies
 * that all aggregations run against the database rather than hydrated models.
 */
final class VelocityForecastingServiceTest extends TestCase
{
    use RefreshDatabase;

    private VelocityForecastingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VelocityForecastingService;
    }

    // ────────────────────────────────────────────────────────────────────
    // EMA — pure math (no DB)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Worked by hand, alpha = 0.2:
     *   EMA_0 = 10
     *   EMA_1 = 0.2*12 + 0.8*10   = 10.4
     *   EMA_2 = 0.2*8  + 0.8*10.4 = 9.92
     *   EMA_3 = 0.2*15 + 0.8*9.92 = 10.936
     */
    public function test_ema_matches_hand_calculation_at_alpha_point_two(): void
    {
        $ema = $this->service->exponentialMovingAverage([10, 12, 8, 15], '0.2');

        $this->assertSame('10.936000000000', $ema);
    }

    /**
     * alpha = 0.5 is equivalent to a simple weighted average that halves
     * residual influence every step. Hand-checked:
     *   EMA_0 = 4
     *   EMA_1 = 0.5*6 + 0.5*4 = 5
     *   EMA_2 = 0.5*2 + 0.5*5 = 3.5
     */
    public function test_ema_matches_hand_calculation_at_alpha_point_five(): void
    {
        $ema = $this->service->exponentialMovingAverage([4, 6, 2], '0.5');

        $this->assertSame('3.500000000000', $ema);
    }

    /**
     * alpha = 1 should make EMA track the most recent observation exactly
     * (every prior observation is multiplied by zero).
     */
    public function test_ema_with_alpha_one_equals_latest_observation(): void
    {
        $ema = $this->service->exponentialMovingAverage([1, 5, 3, 99], '1');

        $this->assertSame('99.000000000000', $ema);
    }

    public function test_ema_of_constant_series_is_that_constant(): void
    {
        $ema = $this->service->exponentialMovingAverage([7, 7, 7, 7, 7], '0.3');

        $this->assertSame('7.000000000000', $ema);
    }

    public function test_ema_of_single_observation_seeds_to_that_value(): void
    {
        $ema = $this->service->exponentialMovingAverage([13], '0.2');

        $this->assertSame('13.000000000000', $ema);
    }

    public function test_ema_of_empty_series_is_zero(): void
    {
        $ema = $this->service->exponentialMovingAverage([], '0.2');

        // BCMath zero at full internal scale
        $this->assertSame(0, bccomp($ema, '0', 12));
    }

    /**
     * Verify that a sustained idle period (zeros) decays EMA toward zero,
     * preventing over-optimistic burnout thresholds after a long holiday.
     *
     * EMA after [20, 0, 0, 0] with alpha = 0.5:
     *   20 -> 10 -> 5 -> 2.5
     */
    public function test_ema_decays_during_idle_weeks(): void
    {
        $ema = $this->service->exponentialMovingAverage([20, 0, 0, 0], '0.5');

        $this->assertSame('2.500000000000', $ema);
    }

    /**
     * Regression: floating-point addition of 0.1 three times yields
     * 0.30000000000000004, but BCMath must produce exactly 0.3.
     */
    public function test_ema_has_no_floating_point_drift(): void
    {
        // Choose a series/alpha pair where IEEE-754 would drift:
        // alpha = 0.1, series = [0, 3, 3, 3]
        // EMA_0 = 0
        // EMA_1 = 0.1*3 + 0.9*0 = 0.3           (float: 0.30000000000000004)
        // EMA_2 = 0.1*3 + 0.9*0.3 = 0.57
        // EMA_3 = 0.1*3 + 0.9*0.57 = 0.813
        $ema = $this->service->exponentialMovingAverage([0, 3, 3, 3], '0.1');

        $this->assertSame('0.813000000000', $ema);
    }

    public function test_invalid_alpha_falls_back_to_default(): void
    {
        // Zero alpha is illegal (would make EMA ignore all new data).
        $zero = $this->service->exponentialMovingAverage([5, 10], '0');
        // Negative alpha is illegal.
        $neg = $this->service->exponentialMovingAverage([5, 10], '-0.5');

        // Both fall back to DEFAULT_ALPHA = 0.2:
        //   0.2*10 + 0.8*5 = 6
        $this->assertSame('6.000000000000', $zero);
        $this->assertSame('6.000000000000', $neg);
    }

    public function test_alpha_above_one_clamps_to_one(): void
    {
        $ema = $this->service->exponentialMovingAverage([1, 99], '1.5');

        $this->assertSame('99.000000000000', $ema);
    }

    /**
     * PHP's numeric parsing (and Laravel's 'numeric' validator) accept
     * scientific notation, but BCMath does not — it throws ValueError on
     * anything outside [+-]?[0-9]*\.?[0-9]+. normaliseAlpha() must
     * canonicalise before handing off to bcadd/bcmul, so "2e-1" produces
     * the identical EMA to "0.2".
     */
    public function test_scientific_notation_alpha_canonicalises_to_fixed_point(): void
    {
        $series = [10, 12, 8, 15];

        $decimal = $this->service->exponentialMovingAverage($series, '0.2');
        $scientific = $this->service->exponentialMovingAverage($series, '2e-1');
        $upperE = $this->service->exponentialMovingAverage($series, '2E-1');
        $mantissa = $this->service->exponentialMovingAverage($series, '0.02e1');

        $this->assertSame($decimal, $scientific);
        $this->assertSame($decimal, $upperE);
        $this->assertSame($decimal, $mantissa);

        // Sanity: the canonical form doesn't change the known value.
        //   EMA_3 = 0.2*15 + 0.8*(0.2*8 + 0.8*(0.2*12 + 0.8*10))
        //         = 0.2*15 + 0.8*(0.2*8 + 0.8*10.4)
        //         = 0.2*15 + 0.8*(1.6 + 8.32)
        //         = 0.2*15 + 0.8*9.92
        //         = 3 + 7.936 = 10.936
        $this->assertSame('10.936000000000', $decimal);
    }

    public function test_scientific_notation_alpha_with_leading_decimal(): void
    {
        // .5e0 = 0.5 — edge case: no leading digit before the decimal point.
        //   EMA_1 = 0.5*6 + 0.5*4 = 5
        //   EMA_2 = 0.5*2 + 0.5*5 = 3.5
        $ema = $this->service->exponentialMovingAverage([4, 6, 2], '.5e0');

        $this->assertSame('3.500000000000', $ema);
    }

    // ────────────────────────────────────────────────────────────────────
    // Success probability — pure math
    // ────────────────────────────────────────────────────────────────────

    #[DataProvider('probabilityProvider')]
    public function test_success_probability_cases(
        string $capacity,
        int $upcoming,
        string $expected,
        string $description,
    ): void {
        $p = $this->service->calculateSuccessProbability($capacity, $upcoming);

        $this->assertSame(
            0,
            bccomp($p, $expected, 6),
            "{$description}: expected {$expected}, got {$p}"
        );
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function probabilityProvider(): array
    {
        return [
            'no upcoming work' => ['10', 0, '1', 'zero load is certain success'],
            'exactly on pace' => ['10', 10, '1', 'load = 1.0 is certain'],
            'under capacity' => ['10', 5, '1', 'load = 0.5 is certain'],
            // load = 25/10 = 2.5 -> p = max(0, 2 - 2.5) = 0
            'burnout 2.5x overload' => ['10', 25, '0', 'beyond 2x capacity impossible'],
            // load = 15/10 = 1.5 -> p = 2 - 1.5 = 0.5
            'mild overload' => ['10', 15, '0.5', '1.5x load yields 50%'],
            // load = 12/10 = 1.2 -> p = 0.8
            'slight overload' => ['10', 12, '0.8', '1.2x load yields 80%'],
            'no history but no work' => ['0', 0, '1', 'nothing to do, nothing to worry about'],
            'no history with work' => ['0', 5, '0', 'cannot complete anything without velocity'],
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Burnout detection — pure math
    // ────────────────────────────────────────────────────────────────────

    public function test_burnout_flagged_when_upcoming_exceeds_capacity(): void
    {
        // The acceptance-criteria case: velocity 10, assigned 25.
        $this->assertTrue($this->service->isBurnoutRisk('10.0000', 25));
    }

    public function test_no_burnout_when_exactly_on_pace(): void
    {
        // Equality is safe — avoids float-drift false positives.
        $this->assertFalse($this->service->isBurnoutRisk('10.000000000000', 10));
    }

    public function test_no_burnout_when_under_capacity(): void
    {
        $this->assertFalse($this->service->isBurnoutRisk('10.0000', 5));
    }

    public function test_burnout_with_zero_capacity_and_nonzero_load(): void
    {
        $this->assertTrue($this->service->isBurnoutRisk('0', 1));
    }

    public function test_no_burnout_with_zero_capacity_and_zero_load(): void
    {
        $this->assertFalse($this->service->isBurnoutRisk('0', 0));
    }

    // ────────────────────────────────────────────────────────────────────
    // DB-backed aggregations
    // ────────────────────────────────────────────────────────────────────

    public function test_aggregate_weekly_effort_groups_by_iso_week(): void
    {
        $user = User::factory()->create();
        // Frozen "now": Thursday 5 March 2026. Week starts Mon 2 Mar.
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Week starting Mon 16 Feb: two items, total 3 + 5 = 8
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-17 10:00:00', EffortScore::Moderate)->create();
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-20 15:00:00', EffortScore::Major)->create();

        // Week starting Mon 23 Feb: one item, 8 points
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-25 09:00:00', EffortScore::Epic)->create();

        // Current week (Mon 2 Mar onward) should be EXCLUDED from history
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-03-03 11:00:00', EffortScore::Epic)->create();

        $history = $this->service->aggregateWeeklyEffort((string) $user->id, $now);

        $this->assertSame([
            ['week_start' => '2026-02-16', 'effort' => 8],
            ['week_start' => '2026-02-23', 'effort' => 8],
        ], $history);
    }

    public function test_aggregate_inserts_zero_weeks_for_gaps(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Week of 9 Feb: 5 points
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-10 10:00:00', EffortScore::Major)->create();
        // (nothing the week of 16 Feb)
        // Week of 23 Feb: 3 points
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-24 10:00:00', EffortScore::Moderate)->create();

        $history = $this->service->aggregateWeeklyEffort((string) $user->id, $now);

        $this->assertSame([
            ['week_start' => '2026-02-09', 'effort' => 5],
            ['week_start' => '2026-02-16', 'effort' => 0],
            ['week_start' => '2026-02-23', 'effort' => 3],
        ], $history);
    }

    public function test_aggregate_returns_empty_for_user_with_no_history(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        $this->assertSame([], $this->service->aggregateWeeklyEffort((string) $user->id, $now));
    }

    public function test_aggregate_isolates_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        Item::factory()->for($alice)->inbox()
            ->completedOn('2026-02-24 10:00:00', EffortScore::Epic)->create();
        Item::factory()->for($bob)->inbox()
            ->completedOn('2026-02-24 10:00:00', EffortScore::Trivial)->create();

        $aliceHistory = $this->service->aggregateWeeklyEffort((string) $alice->id, $now);

        $this->assertSame([
            ['week_start' => '2026-02-23', 'effort' => 8],
        ], $aliceHistory);
    }

    public function test_aggregate_ignores_soft_deleted_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        $item = Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-24 10:00:00', EffortScore::Epic)->create();
        $item->delete();

        $this->assertSame([], $this->service->aggregateWeeklyEffort((string) $user->id, $now));
    }

    public function test_sum_upcoming_effort_aggregates_open_items_due_within_horizon(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Due in 3 days — counts (8 + 5 = 13)
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-08'])->create();
        Item::factory()->for($user)->inbox()->doing()
            ->effort(EffortScore::Major)
            ->state(['due_date' => '2026-03-10'])->create();

        // Due today — counts (2)
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Minor)
            ->state(['due_date' => '2026-03-05'])->create();

        // Due beyond 7-day horizon — excluded
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-20'])->create();

        // Already done — excluded
        Item::factory()->for($user)->inbox()->done()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-08'])->create();

        // Won't do — excluded
        Item::factory()->for($user)->inbox()->wontdo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-08'])->create();

        // No due date — excluded
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)->create();

        $this->assertSame(15, $this->service->sumUpcomingEffort((string) $user->id, $now));
    }

    public function test_sum_upcoming_effort_excludes_past_due_dates(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Overdue (yesterday) — excluded from the forward-looking window.
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-04'])->create();

        $this->assertSame(0, $this->service->sumUpcomingEffort((string) $user->id, $now));
    }

    /**
     * The horizon is the half-open interval [today, today+7). With today =
     * 2026-03-05 that covers exactly 7 days: March 5 through March 11
     * inclusive. March 12 (today+7) is day 8 and must be excluded.
     */
    public function test_sum_upcoming_effort_horizon_is_exactly_seven_days(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Day 1: today (2026-03-05) — included
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)
            ->state(['due_date' => '2026-03-05'])->create();

        // Day 7: today+6 (2026-03-11) — last included day
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Minor)
            ->state(['due_date' => '2026-03-11'])->create();

        // Day 8: today+7 (2026-03-12) — excluded (this is the regression case)
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-12'])->create();

        // Only 1 + 2 = 3; the Epic (8) on day 8 is out of window.
        $this->assertSame(3, $this->service->sumUpcomingEffort((string) $user->id, $now));
    }

    public function test_sum_upcoming_effort_isolates_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        Item::factory()->for($alice)->inbox()->todo()
            ->effort(EffortScore::Moderate)
            ->state(['due_date' => '2026-03-08'])->create();
        Item::factory()->for($bob)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-08'])->create();

        $this->assertSame(3, $this->service->sumUpcomingEffort((string) $alice->id, $now));
    }

    // ────────────────────────────────────────────────────────────────────
    // End-to-end forecast payload
    // ────────────────────────────────────────────────────────────────────

    public function test_forecast_flags_burnout_for_25_point_week_with_10_point_velocity(): void
    {
        // The acceptance-criteria scenario, reproduced end-to-end.
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Seed exactly 10 points for each of the last four complete weeks so
        // EMA = 10 regardless of alpha.
        foreach (['2026-02-03', '2026-02-10', '2026-02-17', '2026-02-24'] as $date) {
            Item::factory()->for($user)->inbox()
                ->completedOn("{$date} 10:00:00", EffortScore::Epic)->create();
            Item::factory()->for($user)->inbox()
                ->completedOn("{$date} 11:00:00", EffortScore::Minor)->create();
        }

        // Assign 25 points due this coming week (8 + 8 + 8 + 1).
        foreach ([EffortScore::Epic, EffortScore::Epic, EffortScore::Epic, EffortScore::Trivial] as $score) {
            Item::factory()->for($user)->inbox()->todo()
                ->effort($score)
                ->state(['due_date' => '2026-03-08'])->create();
        }

        $forecast = $this->service->forecast((string) $user->id, $now);

        $this->assertSame('10.0000', $forecast['weekly_velocity_ema']);
        $this->assertSame(25, $forecast['upcoming_effort']);
        $this->assertTrue($forecast['burnout_risk']);
        // load = 2.5 -> p = 0
        $this->assertSame('0.0000', $forecast['success_probability']);
        $this->assertSame(4, $forecast['history_weeks']);
    }

    public function test_forecast_returns_safe_defaults_for_new_user(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        $forecast = $this->service->forecast((string) $user->id, $now);

        $this->assertSame('0.0000', $forecast['weekly_velocity_ema']);
        $this->assertSame(0, $forecast['upcoming_effort']);
        $this->assertSame('1.0000', $forecast['success_probability']);
        $this->assertFalse($forecast['burnout_risk']);
        $this->assertSame(0, $forecast['history_weeks']);
        $this->assertSame([], $forecast['weekly_history']);
    }

    public function test_forecast_rounds_output_to_four_decimal_places(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12, 0, 0);

        // Single week of 7 points -> EMA = 7.0000
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-24 10:00:00', EffortScore::Major)->create();
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-25 10:00:00', EffortScore::Minor)->create();

        $forecast = $this->service->forecast((string) $user->id, $now);

        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $forecast['weekly_velocity_ema']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $forecast['success_probability']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $forecast['alpha']);
    }
}
