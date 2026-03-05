<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EffortScore;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VelocityForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Auth & response shape
    // ─────────────────────────────────────────────────────────────────────

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/velocity/forecast')->assertUnauthorized();
    }

    public function test_endpoint_returns_complete_forecast_structure(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity/forecast')
            ->assertOk()
            ->assertJsonStructure([
                'velocity_ema',
                'velocity_std_dev',
                'upcoming_effort',
                'capacity_upper_bound',
                'probability_of_success',
                'burnout_risk',
                'weeks_analysed',
                'weekly_series',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SQL aggregation — historical velocity
    // ─────────────────────────────────────────────────────────────────────

    public function test_sql_aggregates_effort_into_correct_weekly_buckets(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        // Week -1 (most recent complete week): two items, 3 + 5 = 8.
        $lastWeek = CarbonImmutable::now()->startOfWeek()->subWeek();
        Item::factory()->for($user)->inbox()->completedAt($lastWeek->addDay(), EffortScore::Medium)->create();
        Item::factory()->for($user)->inbox()->completedAt($lastWeek->addDays(3), EffortScore::Large)->create();

        // Week -2: one item, 8.
        $twoWeeksAgo = CarbonImmutable::now()->startOfWeek()->subWeeks(2);
        Item::factory()->for($user)->inbox()->completedAt($twoWeeksAgo->addDays(2), EffortScore::ExtraLarge)->create();

        $response = $this->actingAs($user)->getJson('/api/velocity/forecast')->assertOk();
        $series = $response->json('weekly_series');

        $this->assertCount(12, $series);
        $this->assertSame(8, $series[11], 'Most recent complete week should sum 3 + 5.');
        $this->assertSame(8, $series[10], 'Two weeks ago should contain the single 8-point item.');
        $this->assertSame(0, $series[9], 'Weeks with no completions must contribute zero.');
    }

    public function test_sql_excludes_current_in_progress_week_from_history(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        // Completed yesterday (inside current week) — must NOT feed the EMA.
        Item::factory()->for($user)->inbox()->completedAt(now()->subDay(), EffortScore::ExtraLarge)->create();

        $response = $this->actingAs($user)->getJson('/api/velocity/forecast')->assertOk();

        $this->assertSame(0, $response->json('velocity_ema'));
    }

    public function test_sql_excludes_wontdo_and_todo_items_from_history(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();
        $lastWeek = CarbonImmutable::now()->startOfWeek()->subWeek()->addDay();

        // Only the 'done' one (3 pts) should count. Cancelled items carry no velocity.
        Item::factory()->for($user)->inbox()->completedAt($lastWeek, EffortScore::Medium)->create();
        Item::factory()->for($user)->inbox()->create([
            'status' => 'wontdo',
            'completed_at' => $lastWeek,
            'effort_score' => EffortScore::ExtraLarge->value,
        ]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)->create();

        $series = $this->actingAs($user)->getJson('/api/velocity/forecast')->json('weekly_series');

        $this->assertSame(3, $series[11]);
    }

    public function test_sql_excludes_soft_deleted_items_from_history(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();
        $lastWeek = CarbonImmutable::now()->startOfWeek()->subWeek()->addDay();

        $deleted = Item::factory()->for($user)->inbox()->completedAt($lastWeek, EffortScore::ExtraLarge)->create();
        $deleted->delete();

        $this->assertSame(
            0,
            $this->actingAs($user)->getJson('/api/velocity/forecast')->json('velocity_ema')
        );
    }

    public function test_sql_isolates_each_user_from_others(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $lastWeek = CarbonImmutable::now()->startOfWeek()->subWeek()->addDay();

        Item::factory()->for($stranger)->inbox()->completedAt($lastWeek, EffortScore::ExtraLarge)->create();

        $this->assertSame(
            0,
            $this->actingAs($me)->getJson('/api/velocity/forecast')->json('velocity_ema')
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // SQL aggregation — upcoming effort
    // ─────────────────────────────────────────────────────────────────────

    public function test_sql_sums_upcoming_effort_within_seven_day_horizon(): void
    {
        // Window is exactly 7 calendar dates: today (day 1) through today+6
        // (day 7). today+7 is the 8th date and must be excluded — fencepost.
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        // Day 1 (today) — inside. 3 pts.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::Medium)
            ->create(['due_date' => now()]);

        // Day 7 (today + 6) — last day inside. 5 pts.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::Large)
            ->create(['due_date' => now()->addDays(6)]);

        // Day 8 (today + 7) — first day OUTSIDE. Regression guard: the prior
        // implementation counted this (8 pts), over-reporting by a full day.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)
            ->create(['due_date' => now()->addDays(7)]);

        // No due date — outside.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)->create();

        $this->assertSame(
            8, // 3 + 5 only. NOT 16.
            $this->actingAs($user)->getJson('/api/velocity/forecast')->json('upcoming_effort')
        );
    }

    public function test_sql_excludes_done_and_wontdo_from_upcoming(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();
        $due = now()->addDays(3)->toDateString();

        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::Medium)->create(['due_date' => $due]);
        Item::factory()->for($user)->inbox()->done()->withEffort(EffortScore::ExtraLarge)->create(['due_date' => $due]);
        Item::factory()->for($user)->inbox()->wontdo()->withEffort(EffortScore::ExtraLarge)->create(['due_date' => $due]);

        $this->assertSame(
            3,
            $this->actingAs($user)->getJson('/api/velocity/forecast')->json('upcoming_effort')
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // End-to-end burnout detection (acceptance criterion)
    // ─────────────────────────────────────────────────────────────────────

    public function test_burnout_flag_fires_when_load_exceeds_historical_capacity(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        // History: steady 10 pts/week for 12 complete weeks.
        $this->seedSteadyHistory($user, 10);

        // Upcoming: 25 pts due this week — 2.5× sustained pace.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)
            ->create(['due_date' => now()->addDays(2)]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)
            ->create(['due_date' => now()->addDays(3)]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)
            ->create(['due_date' => now()->addDays(4)]);
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::Trivial)
            ->create(['due_date' => now()->addDays(5)]);

        $response = $this->actingAs($user)->getJson('/api/velocity/forecast')->assertOk();

        $this->assertEqualsWithDelta(10.0, $response->json('velocity_ema'), 0.01);
        $this->assertSame(25, $response->json('upcoming_effort'));
        $this->assertTrue($response->json('burnout_risk'));
        $this->assertLessThan(0.05, $response->json('probability_of_success'));
    }

    public function test_burnout_flag_stays_clear_when_load_is_sustainable(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        $this->seedSteadyHistory($user, 10);

        // Upcoming: 8 pts — well within a 10-pt/week cadence.
        Item::factory()->for($user)->inbox()->todo()->withEffort(EffortScore::ExtraLarge)
            ->create(['due_date' => now()->addDays(3)]);

        $response = $this->actingAs($user)->getJson('/api/velocity/forecast')->assertOk();

        $this->assertSame(8, $response->json('upcoming_effort'));
        $this->assertFalse($response->json('burnout_risk'));
        $this->assertGreaterThan(0.95, $response->json('probability_of_success'));
    }

    public function test_effort_score_change_flips_burnout_flag_end_to_end(): void
    {
        // Reactivity success criterion: changing effort 1 → 8 shifts the forecast.
        Carbon::setTestNow('2026-03-05 10:00:00');
        $user = User::factory()->create();

        $this->seedSteadyHistory($user, 8);

        // Start with three trivial items = 3 pts upcoming.
        $items = Item::factory()->count(3)->for($user)->inbox()->todo()
            ->withEffort(EffortScore::Trivial)
            ->create(['due_date' => now()->addDays(3)]);

        $before = $this->actingAs($user)->getJson('/api/velocity/forecast');
        $this->assertSame(3, $before->json('upcoming_effort'));
        $this->assertFalse($before->json('burnout_risk'));

        // User bumps each item from 1 → 8 via PATCH. Upcoming jumps to 24.
        foreach ($items as $item) {
            $this->actingAs($user)
                ->patchJson("/api/items/{$item->id}", ['effort_score' => EffortScore::ExtraLarge->value])
                ->assertOk()
                ->assertJsonPath('data.effort_score', EffortScore::ExtraLarge->value);
        }

        $after = $this->actingAs($user)->getJson('/api/velocity/forecast');
        $this->assertSame(24, $after->json('upcoming_effort'));
        $this->assertTrue($after->json('burnout_risk'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // effort_score field — validation & persistence
    // ─────────────────────────────────────────────────────────────────────

    public function test_item_effort_score_defaults_to_trivial_when_omitted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/items', ['title' => 'Quick task', 'status' => 'todo'])
            ->assertCreated();

        $this->assertSame(EffortScore::default()->value, $response->json('data.effort_score'));
    }

    public function test_item_accepts_valid_fibonacci_effort_scores(): void
    {
        $user = User::factory()->create();

        foreach (EffortScore::values() as $score) {
            $this->actingAs($user)
                ->postJson('/api/items', ['title' => 'Sized', 'status' => 'todo', 'effort_score' => $score])
                ->assertCreated()
                ->assertJsonPath('data.effort_score', $score);
        }
    }

    public function test_item_rejects_non_fibonacci_effort_score(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', ['title' => 'Bad', 'status' => 'todo', 'effort_score' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effort_score');
    }

    public function test_item_rejects_out_of_range_effort_score(): void
    {
        $user = User::factory()->create();

        foreach ([0, 13, -1] as $invalid) {
            $this->actingAs($user)
                ->postJson('/api/items', ['title' => 'Bad', 'status' => 'todo', 'effort_score' => $invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('effort_score');
        }
    }

    public function test_db_check_constraint_rejects_non_fibonacci_effort_score(): void
    {
        // Request validation is the first line of defence; this test proves the
        // second line exists. A raw-query UPDATE that bypasses Eloquent, form
        // requests, and the enum cast must still be rejected by the database —
        // otherwise a rogue `effort_score = 200` would silently corrupt every
        // SUM(effort_score) in the velocity aggregation.
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->create(['effort_score' => EffortScore::Trivial->value]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CHECK constraint|check constraint|violates check/i');

        DB::table('items')->where('id', $item->id)->update(['effort_score' => 4]);
    }

    public function test_db_check_constraint_admits_every_fibonacci_value(): void
    {
        // Positive counterpart: the constraint must not over-reject.
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->create(['effort_score' => EffortScore::Trivial->value]);

        foreach (EffortScore::values() as $score) {
            DB::table('items')->where('id', $item->id)->update(['effort_score' => $score]);
            $this->assertSame($score, (int) DB::table('items')->where('id', $item->id)->value('effort_score'));
        }
    }

    public function test_forecast_hot_paths_have_composite_indexes(): void
    {
        // Both forecast queries filter user_id + date-range. Without composite
        // indexes the planner falls back to scanning every item the user owns
        // (or worse, every item in the 7-day window across all tenants).
        // Schema::getIndexes returns each index's column list in declared order.
        $indexes = collect(Schema::getIndexes('items'))->pluck('columns');

        $this->assertTrue(
            $indexes->contains(['user_id', 'completed_at']),
            'Missing (user_id, completed_at) index for the 12-week historical aggregation.',
        );

        $this->assertTrue(
            $indexes->contains(['user_id', 'due_date']),
            'Missing (user_id, due_date) index for the upcoming-effort sum.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Seed exactly $pointsPerWeek of completed effort into every one of the
     * last 12 complete weeks. Composition (5+5 or 8+2) is chosen to hit the
     * target using valid Fibonacci cases.
     */
    private function seedSteadyHistory(User $user, int $pointsPerWeek): void
    {
        $composition = match ($pointsPerWeek) {
            8 => [EffortScore::ExtraLarge],
            10 => [EffortScore::Large, EffortScore::Large],
            default => throw new \InvalidArgumentException("No Fibonacci composition configured for {$pointsPerWeek}"),
        };

        $currentWeekStart = CarbonImmutable::now()->startOfWeek();

        for ($w = 1; $w <= 12; $w++) {
            $weekDay = $currentWeekStart->subWeeks($w)->addDays(2);

            foreach ($composition as $effort) {
                Item::factory()->for($user)->inbox()->completedAt($weekDay, $effort)->create();
            }
        }
    }
}
