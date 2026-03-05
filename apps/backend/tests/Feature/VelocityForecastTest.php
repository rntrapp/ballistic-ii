<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use App\Services\VelocityForecastingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the full stack: migration → SQL aggregation → service → HTTP.
 * Complements the pure-maths unit suite by proving the database layer
 * buckets correctly and the API contract holds.
 */
final class VelocityForecastTest extends TestCase
{
    use RefreshDatabase;

    private VelocityForecastingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VelocityForecastingService;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SQL aggregation layer
    // ─────────────────────────────────────────────────────────────────────

    public function test_weekly_aggregation_buckets_by_completed_at(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // 3 days ago → bucket 0 (most recent week)
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(3), 5)->create();
        // 10 days ago → bucket 1
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(10), 3)->create();
        // 10 days ago, second item — same bucket, effort should SUM
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(10), 2)->create();

        $weeks = $this->service->aggregateWeeklyEffort($user, $now);

        $this->assertCount(VelocityForecastingService::LOOKBACK_WEEKS, $weeks);
        // Array is oldest-first: last element = this week, second-last = last week.
        $this->assertSame(5, $weeks[array_key_last($weeks)]);
        $this->assertSame(5, $weeks[array_key_last($weeks) - 1]); // 3 + 2
        $this->assertSame(0, $weeks[0]); // 12 weeks ago: nothing
    }

    public function test_aggregation_ignores_other_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($alice)->inbox()->completedAt($now->subDays(2), 8)->create();
        Item::factory()->for($bob)->inbox()->completedAt($now->subDays(2), 8)->create();

        $aliceWeeks = $this->service->aggregateWeeklyEffort($alice, $now);

        $this->assertSame(8, $aliceWeeks[array_key_last($aliceWeeks)]);
    }

    public function test_aggregation_ignores_incomplete_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->effort(8)->create();
        Item::factory()->for($user)->inbox()->wontdo()->effort(8)->create();
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(2), 3)->create();

        $weeks = $this->service->aggregateWeeklyEffort($user, $now);

        $this->assertSame(3, $weeks[array_key_last($weeks)]);
    }

    public function test_aggregation_ignores_completions_outside_lookback_window(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // Ancient completion — outside the 12-week window.
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(100), 8)->create();

        $weeks = $this->service->aggregateWeeklyEffort($user, $now);

        $this->assertSame(array_fill(0, VelocityForecastingService::LOOKBACK_WEEKS, 0), $weeks);
    }

    public function test_aggregation_ignores_soft_deleted_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        $item = Item::factory()->for($user)->inbox()->completedAt($now->subDays(2), 8)->create();
        $item->delete();

        $weeks = $this->service->aggregateWeeklyEffort($user, $now);

        $this->assertSame(0, $weeks[array_key_last($weeks)]);
    }

    public function test_aggregation_densifies_gap_weeks_as_zeros(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // Only week 0 and week 4 have activity; weeks 1-3 must be explicit 0.
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(2), 5)->create();
        Item::factory()->for($user)->inbox()->completedAt($now->subDays(30), 3)->create();

        $weeks = $this->service->aggregateWeeklyEffort($user, $now);
        $recent = array_slice($weeks, -5); // last 5 weeks

        $this->assertSame([3, 0, 0, 0, 5], $recent);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Upcoming load
    // ─────────────────────────────────────────────────────────────────────

    public function test_upcoming_load_sums_open_items_due_in_window(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->effort(5)
            ->withDueDate($now->addDays(2)->toDateString())->create();
        Item::factory()->for($user)->inbox()->doing()->effort(3)
            ->withDueDate($now->addDays(6)->toDateString())->create();

        $this->assertSame(8, $this->service->upcomingLoad($user, $now));
    }

    public function test_upcoming_load_excludes_items_past_horizon(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->effort(5)
            ->withDueDate($now->addDays(2)->toDateString())->create();
        Item::factory()->for($user)->inbox()->todo()->effort(8)
            ->withDueDate($now->addDays(30)->toDateString())->create();

        $this->assertSame(5, $this->service->upcomingLoad($user, $now));
    }

    public function test_upcoming_load_excludes_completed_and_cancelled(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);
        $due = $now->addDays(3)->toDateString();

        Item::factory()->for($user)->inbox()->done()->effort(8)->withDueDate($due)->create();
        Item::factory()->for($user)->inbox()->wontdo()->effort(8)->withDueDate($due)->create();
        Item::factory()->for($user)->inbox()->todo()->effort(2)->withDueDate($due)->create();

        $this->assertSame(2, $this->service->upcomingLoad($user, $now));
    }

    public function test_upcoming_load_excludes_overdue_items(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        // Overdue items sit outside the forward window — they're a separate
        // concern (the Overdue scope already surfaces them).
        Item::factory()->for($user)->inbox()->todo()->effort(8)
            ->withDueDate($now->subDays(5)->toDateString())->create();

        $this->assertSame(0, $this->service->upcomingLoad($user, $now));
    }

    public function test_upcoming_load_excludes_items_without_due_date(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::create(2026, 3, 5, 12);

        Item::factory()->for($user)->inbox()->todo()->effort(8)->create();

        $this->assertSame(0, $this->service->upcomingLoad($user, $now));
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/velocity/forecast')->assertUnauthorized();
    }

    public function test_endpoint_returns_complete_forecast_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity/forecast')
            ->assertOk()
            ->assertJsonStructure([
                'velocity',
                'std_dev',
                'capacity_upper',
                'upcoming_load',
                'burnout_risk',
                'probability_of_success',
                'weekly_history',
                'sample_weeks',
            ]);
    }

    public function test_endpoint_flags_burnout_for_overloaded_week(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));
        $user = User::factory()->create();
        $now = CarbonImmutable::now();

        // Establish a velocity of ~10 pts/week.
        foreach ([1, 2, 3, 4, 5, 6] as $weeksAgo) {
            Item::factory()->for($user)->inbox()
                ->completedAt($now->subWeeks($weeksAgo)->addDay(), 5)->create();
            Item::factory()->for($user)->inbox()
                ->completedAt($now->subWeeks($weeksAgo)->addDays(3), 5)->create();
        }

        // Schedule 25 pts for the coming week.
        Item::factory()->for($user)->inbox()->todo()->effort(8)
            ->withDueDate($now->addDays(2)->toDateString())->create();
        Item::factory()->for($user)->inbox()->todo()->effort(8)
            ->withDueDate($now->addDays(4)->toDateString())->create();
        Item::factory()->for($user)->inbox()->todo()->effort(8)
            ->withDueDate($now->addDays(6)->toDateString())->create();
        Item::factory()->for($user)->inbox()->todo()->effort(1)
            ->withDueDate($now->addDays(6)->toDateString())->create();

        $this->actingAs($user)
            ->getJson('/api/velocity/forecast')
            ->assertOk()
            ->assertJson(['burnout_risk' => true, 'upcoming_load' => 25]);

        CarbonImmutable::setTestNow();
    }

    public function test_endpoint_reports_safe_when_load_is_manageable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));
        $user = User::factory()->create();
        $now = CarbonImmutable::now();

        // Steady 10 pts/week.
        foreach ([1, 2, 3, 4] as $weeksAgo) {
            Item::factory()->for($user)->inbox()
                ->completedAt($now->subWeeks($weeksAgo)->addDay(), 5)->create();
            Item::factory()->for($user)->inbox()
                ->completedAt($now->subWeeks($weeksAgo)->addDays(3), 5)->create();
        }

        // Light 5-pt load.
        Item::factory()->for($user)->inbox()->todo()->effort(5)
            ->withDueDate($now->addDays(3)->toDateString())->create();

        $this->actingAs($user)
            ->getJson('/api/velocity/forecast')
            ->assertOk()
            ->assertJson(['burnout_risk' => false, 'upcoming_load' => 5]);

        CarbonImmutable::setTestNow();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reactivity contract: effort change shifts the forecast
    // ─────────────────────────────────────────────────────────────────────

    public function test_bumping_effort_from_one_to_eight_shifts_forecast(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));
        $user = User::factory()->create();
        $now = CarbonImmutable::now();

        // Modest velocity so 8 pts is meaningful.
        foreach ([1, 2, 3] as $weeksAgo) {
            Item::factory()->for($user)->inbox()
                ->completedAt($now->subWeeks($weeksAgo)->addDay(), 3)->create();
        }

        $item = Item::factory()->for($user)->inbox()->todo()->effort(1)
            ->withDueDate($now->addDays(3)->toDateString())->create();

        $before = $this->actingAs($user)->getJson('/api/velocity/forecast')->json();
        $this->assertFalse($before['burnout_risk']);
        $this->assertSame(1, $before['upcoming_load']);

        // Success criteria: changing effort from 1 → 8 must instantly shift the metric.
        $this->actingAs($user)
            ->patchJson("/api/items/{$item->id}", ['effort_score' => 8])
            ->assertOk();

        $after = $this->actingAs($user)->getJson('/api/velocity/forecast')->json();
        $this->assertTrue($after['burnout_risk']);
        $this->assertSame(8, $after['upcoming_load']);
        $this->assertLessThan($before['probability_of_success'], $after['probability_of_success']);

        CarbonImmutable::setTestNow();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────

    public function test_effort_score_rejects_non_fibonacci_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Bad effort',
                'status' => 'todo',
                'effort_score' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effort_score');
    }

    /**
     * The FormRequest rule above only guards the HTTP boundary. Seeders,
     * tinker, bulk imports and any future write path that bypasses
     * StoreItemRequest must also be stopped — so the CHECK constraint has
     * to hold even when Eloquent writes directly.
     */
    public function test_database_check_constraint_rejects_non_fibonacci(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        Item::factory()->for($user)->inbox()->create(['effort_score' => 4]);
    }

    public function test_database_check_constraint_rejects_zero(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        Item::factory()->for($user)->inbox()->create(['effort_score' => 0]);
    }

    public function test_effort_score_accepts_all_fibonacci_values(): void
    {
        $user = User::factory()->create();

        foreach (VelocityForecastingService::FIBONACCI as $score) {
            $this->actingAs($user)
                ->postJson('/api/items', [
                    'title' => "Effort {$score}",
                    'status' => 'todo',
                    'effort_score' => $score,
                ])
                ->assertCreated()
                ->assertJsonPath('data.effort_score', $score);
        }
    }

    public function test_effort_score_defaults_to_one_when_omitted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/items', ['title' => 'Default effort', 'status' => 'todo'])
            ->assertCreated();

        $this->assertSame(1, $response->json('data.effort_score'));
    }
}
