<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EffortScore;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VelocityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze "now" so date arithmetic is deterministic across the whole
        // request cycle (the controller uses CarbonImmutable::now()).
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/velocity')->assertStatus(401);
    }

    public function test_returns_forecast_payload_shape(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/velocity');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'weekly_velocity_ema',
                    'upcoming_effort',
                    'success_probability',
                    'burnout_risk',
                    'alpha',
                    'history_weeks',
                    'weekly_history',
                ],
            ]);
    }

    public function test_burnout_flag_set_when_scheduled_effort_exceeds_velocity(): void
    {
        $user = User::factory()->create();

        // Historical velocity of exactly 10 points/week for three weeks.
        foreach (['2026-02-10', '2026-02-17', '2026-02-24'] as $date) {
            Item::factory()->for($user)->inbox()
                ->completedOn("{$date} 09:00:00", EffortScore::Epic)->create();
            Item::factory()->for($user)->inbox()
                ->completedOn("{$date} 10:00:00", EffortScore::Minor)->create();
        }

        // 25 points due this week.
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)->state(['due_date' => '2026-03-09'])->create();
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)->state(['due_date' => '2026-03-09'])->create();
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Epic)->state(['due_date' => '2026-03-09'])->create();
        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->state(['due_date' => '2026-03-09'])->create();

        $response = $this->actingAs($user)->getJson('/api/velocity');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'weekly_velocity_ema' => '10.0000',
                    'upcoming_effort' => 25,
                    'burnout_risk' => true,
                    'success_probability' => '0.0000',
                ],
            ]);
    }

    public function test_no_burnout_when_under_capacity(): void
    {
        $user = User::factory()->create();

        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-24 09:00:00', EffortScore::Epic)->create();
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-25 09:00:00', EffortScore::Minor)->create();

        Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Moderate)
            ->state(['due_date' => '2026-03-08'])->create();

        $response = $this->actingAs($user)->getJson('/api/velocity');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'weekly_velocity_ema' => '10.0000',
                    'upcoming_effort' => 3,
                    'burnout_risk' => false,
                    'success_probability' => '1.0000',
                ],
            ]);
    }

    public function test_new_user_with_no_history_receives_safe_defaults(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity')
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'weekly_velocity_ema' => '0.0000',
                    'upcoming_effort' => 0,
                    'burnout_risk' => false,
                    'success_probability' => '1.0000',
                    'history_weeks' => 0,
                    'weekly_history' => [],
                ],
            ]);
    }

    public function test_alpha_query_param_overrides_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=0.5')
            ->assertStatus(200)
            ->assertJson(['data' => ['alpha' => '0.5000']]);
    }

    /**
     * Laravel's 'numeric' validator accepts scientific notation ("2e-1")
     * but BCMath does not. The service must canonicalise to fixed-point
     * decimal before handing the value to bcadd/bcmul — otherwise this
     * request would explode with a ValueError → 500.
     */
    public function test_alpha_accepts_scientific_notation(): void
    {
        $user = User::factory()->create();

        // 2e-1 = 0.2
        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=2e-1')
            ->assertStatus(200)
            ->assertJson(['data' => ['alpha' => '0.2000']]);

        // 1E-2 = 0.01
        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=1E-2')
            ->assertStatus(200)
            ->assertJson(['data' => ['alpha' => '0.0100']]);

        // 5.0e-1 = 0.5
        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=5.0e-1')
            ->assertStatus(200)
            ->assertJson(['data' => ['alpha' => '0.5000']]);
    }

    public function test_invalid_alpha_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alpha');

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=1.5')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alpha');

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alpha');
    }

    public function test_forecast_excludes_other_users_items(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Item::factory()->for($bob)->inbox()
            ->completedOn('2026-02-24 09:00:00', EffortScore::Epic)->create();
        Item::factory()->for($bob)->inbox()->todo()
            ->effort(EffortScore::Epic)
            ->state(['due_date' => '2026-03-08'])->create();

        $this->actingAs($alice)
            ->getJson('/api/velocity')
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'weekly_velocity_ema' => '0.0000',
                    'upcoming_effort' => 0,
                ],
            ]);
    }

    public function test_weekly_history_is_chronological_oldest_first(): void
    {
        $user = User::factory()->create();

        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-10 09:00:00', EffortScore::Major)->create();
        Item::factory()->for($user)->inbox()
            ->completedOn('2026-02-24 09:00:00', EffortScore::Moderate)->create();

        $response = $this->actingAs($user)->getJson('/api/velocity');

        $history = $response->json('data.weekly_history');

        $this->assertCount(3, $history);
        $this->assertSame('2026-02-09', $history[0]['week_start']);
        $this->assertSame('2026-02-16', $history[1]['week_start']);
        $this->assertSame('2026-02-23', $history[2]['week_start']);
        $this->assertSame(5, $history[0]['effort']);
        $this->assertSame(0, $history[1]['effort']);
        $this->assertSame(3, $history[2]['effort']);
    }

    // ────────────────────────────────────────────────────────────────────
    // effort_score validation on Item endpoints
    // ────────────────────────────────────────────────────────────────────

    public function test_item_can_be_created_with_valid_effort_score(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Big Task',
            'status' => 'todo',
            'effort_score' => EffortScore::Epic->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['effort_score' => 8]);

        $this->assertDatabaseHas('items', [
            'title' => 'Big Task',
            'effort_score' => 8,
        ]);
    }

    public function test_item_defaults_to_trivial_effort_when_unspecified(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Unscored Task',
            'status' => 'todo',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['effort_score' => EffortScore::default()->value]);
    }

    public function test_item_rejects_non_fibonacci_effort_score(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Invalid',
                'status' => 'todo',
                'effort_score' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effort_score');

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Invalid',
                'status' => 'todo',
                'effort_score' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effort_score');
    }

    public function test_item_effort_score_can_be_updated(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->create();

        $this->actingAs($user)
            ->patchJson("/api/items/{$item->id}", ['effort_score' => EffortScore::Epic->value])
            ->assertStatus(200)
            ->assertJsonFragment(['effort_score' => 8]);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'effort_score' => 8,
        ]);
    }

    /**
     * The DB-level CHECK constraint must reject non-Fibonacci values even
     * when the application layer (Form Request validation) is bypassed —
     * e.g. raw DB inserts, tinker sessions, or buggy seeders.
     */
    public function test_database_check_constraint_rejects_non_fibonacci_effort_score(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->create();

        // 4 is not in {1, 2, 3, 5, 8}
        $this->expectException(QueryException::class);
        DB::table('items')
            ->where('id', $item->id)
            ->update(['effort_score' => 4]);
    }

    public function test_database_check_constraint_rejects_zero_effort_score(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->create();

        $this->expectException(QueryException::class);
        DB::table('items')
            ->where('id', $item->id)
            ->update(['effort_score' => 0]);
    }

    public function test_database_check_constraint_rejects_effort_score_above_max(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->create();

        $this->expectException(QueryException::class);
        DB::table('items')
            ->where('id', $item->id)
            ->update(['effort_score' => 13]);
    }

    public function test_database_check_constraint_permits_every_fibonacci_value(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()
            ->effort(EffortScore::Trivial)->create();

        foreach (EffortScore::values() as $score) {
            DB::table('items')
                ->where('id', $item->id)
                ->update(['effort_score' => $score]);

            $this->assertDatabaseHas('items', [
                'id' => $item->id,
                'effort_score' => $score,
            ]);
        }
    }
}
