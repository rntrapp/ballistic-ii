<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VelocityTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // Authentication & validation
    // ────────────────────────────────────────────────────────────────────────

    public function test_velocity_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/velocity')->assertUnauthorized();
    }

    public function test_velocity_endpoint_returns_forecast_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity')
            ->assertOk()
            ->assertJsonStructure([
                'weekly_velocity',
                'upcoming_effort',
                'burnout_risk',
                'success_probability',
                'weekly_history' => [['week_start', 'effort']],
                'lookback_weeks',
                'alpha',
            ]);
    }

    public function test_velocity_endpoint_rejects_invalid_lookback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity?lookback_weeks=0')
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson('/api/velocity?lookback_weeks=53')
            ->assertStatus(422);
    }

    public function test_velocity_endpoint_rejects_invalid_alpha(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=0')
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=1.5')
            ->assertStatus(422);
    }

    public function test_velocity_endpoint_rejects_scientific_notation_alpha(): void
    {
        $user = User::factory()->create();

        // "3e-1" and "3E-1" pass PHP's is_numeric() but BCMath rejects them with
        // a ValueError. The validator must catch these before they reach the service.
        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=3e-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alpha');

        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=3E-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alpha');

        // Equivalent plain-decimal form must still work.
        $this->actingAs($user)
            ->getJson('/api/velocity?alpha=0.3')
            ->assertOk()
            ->assertJsonPath('alpha', '0.3');
    }

    public function test_velocity_endpoint_accepts_custom_lookback_and_alpha(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/velocity?lookback_weeks=4&alpha=0.5')
            ->assertOk()
            ->assertJsonPath('lookback_weeks', 4)
            ->assertJsonPath('alpha', '0.5');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Burnout acceptance scenario (velocity 10 vs load 25)
    // ────────────────────────────────────────────────────────────────────────

    public function test_velocity_endpoint_flags_burnout_when_overloaded(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));
        $user = User::factory()->create();

        // Steady 10-point history across 4 weeks ⇒ EMA = 10.
        for ($w = 0; $w < 4; $w++) {
            Item::factory()->for($user)->inbox()->withEffort(8)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek())
                ->create();
            Item::factory()->for($user)->inbox()->withEffort(2)
                ->completedAt(CarbonImmutable::now()->subWeeks($w)->startOfWeek()->addDay())
                ->create();
        }

        // 25 points due this week.
        foreach ([8, 8, 8, 1] as $i => $effort) {
            Item::factory()->for($user)->inbox()->todo()->withEffort($effort)
                ->create(['due_date' => CarbonImmutable::now()->addDays($i + 1)->toDateString()]);
        }

        $this->actingAs($user)
            ->getJson('/api/velocity?lookback_weeks=4')
            ->assertOk()
            ->assertJsonPath('weekly_velocity', '10.000000')
            ->assertJsonPath('upcoming_effort', 25)
            ->assertJsonPath('burnout_risk', true)
            ->assertJsonPath('success_probability', '0.400000');

        CarbonImmutable::setTestNow();
    }

    public function test_velocity_endpoint_isolates_users(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 5, 12));
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Other user has a mountain of work; should not leak into our forecast.
        Item::factory()->for($other)->inbox()->todo()->withEffort(8)
            ->create(['due_date' => CarbonImmutable::now()->addDay()->toDateString()]);

        $this->actingAs($user)
            ->getJson('/api/velocity')
            ->assertOk()
            ->assertJsonPath('upcoming_effort', 0);

        CarbonImmutable::setTestNow();
    }

    // ────────────────────────────────────────────────────────────────────────
    // effort_score CRUD surface
    // ────────────────────────────────────────────────────────────────────────

    public function test_item_created_with_effort_score(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Heavy task',
                'status' => 'todo',
                'effort_score' => 8,
            ])
            ->assertCreated()
            ->assertJsonPath('data.effort_score', 8);

        $this->assertDatabaseHas('items', [
            'title' => 'Heavy task',
            'effort_score' => 8,
        ]);
    }

    public function test_item_defaults_effort_score_to_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Default effort',
                'status' => 'todo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.effort_score', 1);
    }

    public function test_item_rejects_non_fibonacci_effort_score(): void
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

    public function test_item_rejects_zero_effort_score(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Zero effort',
                'status' => 'todo',
                'effort_score' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effort_score');
    }

    public function test_item_effort_score_can_be_updated(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->inbox()->todo()->withEffort(1)->create();

        $this->actingAs($user)
            ->patchJson("/api/items/{$item->id}", ['effort_score' => 8])
            ->assertOk()
            ->assertJsonPath('data.effort_score', 8);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'effort_score' => 8,
        ]);
    }
}
