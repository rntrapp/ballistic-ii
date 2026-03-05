<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Item;
use App\Models\User;
use App\Services\VelocityForecastingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exhaustive coverage for {@see VelocityForecastingService}.
 *
 * The EMA math is tested in isolation (pure function, no DB) and the
 * aggregation/burnout logic is tested against an in-memory SQLite
 * database seeded via factories.
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

    // ────────────────────────────────────────────────────────────────────────
    // Fibonacci scale generator
    // ────────────────────────────────────────────────────────────────────────

    public function test_fibonacci_scale_generates_sequence_from_recurrence(): void
    {
        $this->assertSame([1, 2, 3, 5, 8], VelocityForecastingService::fibonacciScale(8));
        $this->assertSame([1, 2, 3, 5, 8, 13, 21], VelocityForecastingService::fibonacciScale(21));
        $this->assertSame([1], VelocityForecastingService::fibonacciScale(1));
        $this->assertSame([], VelocityForecastingService::fibonacciScale(0));
    }

    public function test_fibonacci_scale_defaults_to_max_effort_cap(): void
    {
        $this->assertSame(
            VelocityForecastingService::fibonacciScale(VelocityForecastingService::MAX_EFFORT),
            VelocityForecastingService::fibonacciScale(),
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // EMA — pure-math tests (no DB)
    // ────────────────────────────────────────────────────────────────────────

    public function test_ema_of_empty_series_is_zero(): void
    {
        $this->assertSame('0.000000', $this->service->exponentialMovingAverage([], '0.3'));
    }

    public function test_ema_of_single_observation_equals_that_observation(): void
    {
        $this->assertSame('10.000000', $this->service->exponentialMovingAverage([10], '0.3'));
        $this->assertSame('7.000000', $this->service->exponentialMovingAverage([7], '0.9'));
    }

    public function test_ema_of_constant_series_equals_the_constant(): void
    {
        // A constant series converges immediately: EMA never leaves 10.
        $this->assertSame(
            '10.000000',
            $this->service->exponentialMovingAverage([10, 10, 10, 10, 10], '0.3'),
        );
    }

    public function test_ema_matches_hand_computed_reference(): void
    {
        // Series [10, 20], α = 0.3:
        //   EMA_0 = 10
        //   EMA_1 = 0.3·20 + 0.7·10 = 6 + 7 = 13
        $this->assertSame(
            '13.000000',
            $this->service->exponentialMovingAverage([10, 20], '0.3'),
        );

        // Series [10, 20, 30], α = 0.3:
        //   EMA_2 = 0.3·30 + 0.7·13 = 9 + 9.1 = 18.1
        $this->assertSame(
            '18.100000',
            $this->service->exponentialMovingAverage([10, 20, 30], '0.3'),
        );
    }

    public function test_ema_with_alpha_one_tracks_latest_observation(): void
    {
        // α = 1 ⇒ EMA_t = X_t (no smoothing).
        $this->assertSame(
            '8.000000',
            $this->service->exponentialMovingAverage([1, 5, 3, 8], '1'),
        );
    }

    public function test_ema_with_low_alpha_weights_history_heavily(): void
    {
        // Series [0, 0, 0, 100], α = 0.1:
        //   EMA_3 = 0.1·100 + 0.9·0 = 10
        $this->assertSame(
            '10.000000',
            $this->service->exponentialMovingAverage([0, 0, 0, 100], '0.1'),
        );
    }

    public function test_ema_uses_fixed_point_precision_not_float(): void
    {
        // Series [1, 2], α = 0.1:
        //   EMA_1 = 0.1·2 + 0.9·1 = 0.2 + 0.9 = 1.1 (exact).
        // A naïve float implementation would yield 1.1000000000000001.
        $this->assertSame(
            '1.100000',
            $this->service->exponentialMovingAverage([1, 2], '0.1'),
        );
    }

    #[DataProvider('invalidAlphaProvider')]
    public function test_ema_rejects_alpha_outside_open_closed_interval(string $alpha): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->exponentialMovingAverage([1, 2, 3], $alpha);
    }

    /** @return array<string, array{string}> */
    public static function invalidAlphaProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-0.1'],
            'greater than one' => ['1.01'],
            'far too large' => ['2'],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Burnout threshold — pure-math tests
    // ────────────────────────────────────────────────────────────────────────

    public function test_burnout_risk_true_when_upcoming_exceeds_velocity(): void
    {
        // Acceptance criterion: velocity 10, upcoming 25 ⇒ burnout.
        $this->assertTrue($this->service->isBurnoutRisk('10.000000', 25));
    }

    public function test_burnout_risk_false_when_upcoming_equals_velocity(): void
    {
        // Exact boundary: not over capacity, so not burnout.
        $this->assertFalse($this->service->isBurnoutRisk('10.000000', 10));
    }

    public function test_burnout_risk_false_when_upcoming_below_velocity(): void
    {
        $this->assertFalse($this->service->isBurnoutRisk('10.000000', 5));
    }

    public function test_burnout_risk_handles_fractional_velocity_precisely(): void
    {
        // Velocity 10.000001 > upcoming 10 ⇒ no burnout.
        $this->assertFalse($this->service->isBurnoutRisk('10.000001', 10));

        // Velocity 9.999999 < upcoming 10 ⇒ burnout.
        $this->assertTrue($this->service->isBurnoutRisk('9.999999', 10));
    }

    public function test_burnout_risk_with_zero_velocity_and_nonzero_load(): void
    {
        $this->assertTrue($this->service->isBurnoutRisk('0.000000', 1));
    }

    public function test_burnout_risk_with_zero_velocity_and_zero_load(): void
    {
        $this->assertFalse($this->service->isBurnoutRisk('0.000000', 0));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Success probability — pure-math tests
    // ────────────────────────────────────────────────────────────────────────

    public function test_probability_is_one_when_no_upcoming_work(): void
    {
        $this->assertSame('1.000000', $this->service->successProbability('5.000000', 0));
        $this->assertSame('1.000000', $this->service->successProbability('0.000000', 0));
    }

    public function test_probability_is_zero_with_zero_velocity_and_work_pending(): void
    {
        $this->assertSame('0.000000', $this->service->successProbability('0.000000', 5));
    }

    public function test_probability_is_one_when_capacity_meets_or_exceeds_load(): void
    {
        $this->assertSame('1.000000', $this->service->successProbability('10.000000', 10));
        $this->assertSame('1.000000', $this->service->successProbability('20.000000', 10));
    }

    public function test_probability_is_capacity_to_load_ratio_when_overloaded(): void
    {
        // 10/25 = 0.4
        $this->assertSame('0.400000', $this->service->successProbability('10.000000', 25));

        // 5/8 = 0.625
        $this->assertSame('0.625000', $this->service->successProbability('5.000000', 8));
    }

    public function test_probability_is_always_within_closed_unit_interval(): void
    {
        $cases = [
            ['0.000000', 0], ['0.000000', 100],
            ['50.000000', 1], ['1.000000', 100],
            ['7.500000', 15],
        ];
        foreach ($cases as [$v, $u]) {
            $p = $this->service->successProbability($v, $u);
            $this->assertTrue(bccomp($p, '0', 6) >= 0, "probability {$p} < 0");
            $this->assertTrue(bccomp($p, '1', 6) <= 0, "probability {$p} > 1");
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Weekly history aggregation — DB tests
    // ────────────────────────────────────────────────────────────────────────

    public function test_weekly_history_is_zero_filled_when_no_completions(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12); // Thursday

        $history = $this->service->weeklyEffortHistory($user, $now, 4);

        $this->assertCount(4, $history);
        foreach ($history as $week) {
            $this->assertSame(0, $week['effort']);
            $this->assertArrayHasKey('week_start', $week);
        }
    }

    public function test_weekly_history_returns_empty_for_zero_lookback(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        $this->assertSame([], $this->service->weeklyEffortHistory($user, $now, 0));
    }

    public function test_weekly_history_aggregates_effort_by_iso_week(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12); // Thursday of the current week

        // Current week (Mon 2-Mar-26 → Sun 8-Mar-26): 3 + 5 = 8 points
        Item::factory()->for($user)->inbox()->withEffort(3)
            ->completedAt($now->startOfWeek()->addDay())
            ->create();
        Item::factory()->for($user)->inbox()->withEffort(5)
            ->completedAt($now->startOfWeek()->addDays(2))
            ->create();

        // Previous week: 2 points
        Item::factory()->for($user)->inbox()->withEffort(2)
            ->completedAt($now->subWeek()->startOfWeek())
            ->create();

        $history = $this->service->weeklyEffortHistory($user, $now, 2);

        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]['effort']); // oldest first
        $this->assertSame(8, $history[1]['effort']);
    }

    public function test_weekly_history_ignores_items_outside_window(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // Completed 10 weeks ago — outside a 4-week window.
        Item::factory()->for($user)->inbox()->withEffort(8)
            ->completedAt($now->subWeeks(10))
            ->create();

        $history = $this->service->weeklyEffortHistory($user, $now, 4);
        $total = array_sum(array_column($history, 'effort'));

        $this->assertSame(0, $total);
    }

    public function test_weekly_history_ignores_non_done_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // A 'wontdo' item with a completed_at timestamp should be excluded.
        Item::factory()->for($user)->inbox()->withEffort(8)->create([
            'status' => 'wontdo',
            'completed_at' => $now->subDay(),
        ]);

        $history = $this->service->weeklyEffortHistory($user, $now, 2);
        $this->assertSame(0, array_sum(array_column($history, 'effort')));
    }

    public function test_weekly_history_scoped_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($other)->inbox()->withEffort(8)
            ->completedAt($now->subDay())
            ->create();

        $history = $this->service->weeklyEffortHistory($user, $now, 2);
        $this->assertSame(0, array_sum(array_column($history, 'effort')));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Upcoming effort — DB tests
    // ────────────────────────────────────────────────────────────────────────

    public function test_upcoming_effort_sums_open_items_due_in_next_seven_days(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // Window is [today, today+6] — exactly 7 calendar days.
        Item::factory()->for($user)->inbox()->todo()->withEffort(5)
            ->create(['due_date' => $now->toDateString()]);            // day 1 (today)
        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => $now->addDays(3)->toDateString()]); // day 4
        Item::factory()->for($user)->inbox()->doing()->withEffort(2)
            ->create(['due_date' => $now->addDays(6)->toDateString()]); // day 7 (upper bound, inclusive)

        $this->assertSame(15, $this->service->upcomingEffort($user, $now));
    }

    public function test_upcoming_effort_excludes_items_due_beyond_seven_days(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // today+7 is the 8th calendar day — must be outside the window.
        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => $now->addDays(7)->toDateString()]);

        $this->assertSame(0, $this->service->upcomingEffort($user, $now));
    }

    public function test_upcoming_effort_excludes_completed_and_cancelled_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->done()->withEffort(5)
            ->create(['due_date' => $now->addDay()->toDateString()]);
        Item::factory()->for($user)->inbox()->wontdo()->withEffort(3)
            ->create(['due_date' => $now->addDay()->toDateString()]);

        $this->assertSame(0, $this->service->upcomingEffort($user, $now));
    }

    public function test_upcoming_effort_excludes_items_without_due_date(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => null]);

        $this->assertSame(0, $this->service->upcomingEffort($user, $now));
    }

    public function test_upcoming_effort_excludes_past_due_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => $now->subDay()->toDateString()]);

        $this->assertSame(0, $this->service->upcomingEffort($user, $now));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Full forecast — integration of all pieces
    // ────────────────────────────────────────────────────────────────────────

    public function test_forecast_burnout_scenario_velocity_10_load_25(): void
    {
        $user = User::factory()->create();
        // Freeze "now" for both the data setup AND the forecast.
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));

        // Build a history that produces an EMA of exactly 10:
        // every week completed 10 points → EMA stays at 10.
        for ($w = 0; $w < 4; $w++) {
            Item::factory()->for($user)->inbox()->withEffort(8)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek())
                ->create();
            Item::factory()->for($user)->inbox()->withEffort(2)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek()->addDay())
                ->create();
        }

        // Schedule 25 points of work due this coming week.
        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => CarbonImmutable::now()->addDays(2)->toDateString()]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => CarbonImmutable::now()->addDays(3)->toDateString()]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => CarbonImmutable::now()->addDays(4)->toDateString()]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(1)
            ->create(['due_date' => CarbonImmutable::now()->addDays(5)->toDateString()]);

        $forecast = $this->service->forecast($user, lookbackWeeks: 4);

        $this->assertSame('10.000000', $forecast['weekly_velocity']);
        $this->assertSame(25, $forecast['upcoming_effort']);
        $this->assertTrue($forecast['burnout_risk']);
        $this->assertSame('0.400000', $forecast['success_probability']);
        $this->assertCount(4, $forecast['weekly_history']);

        CarbonImmutable::setTestNow();
    }

    public function test_forecast_healthy_scenario_no_burnout(): void
    {
        $user = User::factory()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));

        // Steady 10-point weeks.
        for ($w = 0; $w < 4; $w++) {
            Item::factory()->for($user)->inbox()->withEffort(5)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek())
                ->create();
            Item::factory()->for($user)->inbox()->withEffort(5)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek()->addDay())
                ->create();
        }

        // Only 5 points scheduled.
        Item::factory()->for($user)->inbox()->todo()->withEffort(5)
            ->create(['due_date' => CarbonImmutable::now()->addDays(2)->toDateString()]);

        $forecast = $this->service->forecast($user, lookbackWeeks: 4);

        $this->assertSame('10.000000', $forecast['weekly_velocity']);
        $this->assertSame(5, $forecast['upcoming_effort']);
        $this->assertFalse($forecast['burnout_risk']);
        $this->assertSame('1.000000', $forecast['success_probability']);

        CarbonImmutable::setTestNow();
    }

    public function test_forecast_with_no_history_and_no_load(): void
    {
        $user = User::factory()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));

        $forecast = $this->service->forecast($user, lookbackWeeks: 4);

        $this->assertSame('0.000000', $forecast['weekly_velocity']);
        $this->assertSame(0, $forecast['upcoming_effort']);
        $this->assertFalse($forecast['burnout_risk']);
        $this->assertSame('1.000000', $forecast['success_probability']);

        CarbonImmutable::setTestNow();
    }
}
