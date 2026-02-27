<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CognitiveEvent;
use App\Models\CognitiveProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CognitivePhaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/user/cognitive-phase');
        $response->assertStatus(401);
    }

    public function test_returns_no_profile_message_with_insufficient_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/user/cognitive-phase');

        $response->assertStatus(200)
            ->assertJson([
                'has_profile' => false,
            ])
            ->assertJsonStructure(['message']);
    }

    public function test_returns_phase_snapshot_with_sufficient_data(): void
    {
        $user = User::factory()->create();
        CognitiveProfile::factory()->peakedNow()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/user/cognitive-phase');

        $response->assertStatus(200)
            ->assertJsonPath('data.has_profile', true)
            ->assertJsonStructure([
                'data' => [
                    'has_profile',
                    'phase',
                    'dominant_cycle_minutes',
                    'next_peak_at',
                    'confidence',
                    'amplitude_fraction',
                    'sample_count',
                ],
            ]);

        $this->assertContains($response->json('data.phase'), ['peak', 'trough', 'recovery']);
    }

    public function test_returns_next_peak_in_future(): void
    {
        $user = User::factory()->create();
        CognitiveProfile::factory()->create([
            'user_id' => $user->id,
            'phase_anchor_at' => CarbonImmutable::now()->subMinutes(30),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/user/cognitive-phase');

        $nextPeakAt = $response->json('data.next_peak_at');
        $this->assertTrue(CarbonImmutable::parse($nextPeakAt)->isAfter(CarbonImmutable::now()->subSeconds(5)));
    }

    public function test_events_endpoint_returns_todays_completions_only(): void
    {
        $user = User::factory()->create();

        // Today's completion — should appear
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        // Yesterday's completion — should NOT appear
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/user/cognitive-events');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_events_endpoint_excludes_other_users_events(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        CognitiveEvent::factory()->completed()->create([
            'user_id' => $me->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $other->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        $response = $this->actingAs($me)->getJson('/api/v1/user/cognitive-events');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_events_endpoint_excludes_started_events(): void
    {
        $user = User::factory()->create();

        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
        CognitiveEvent::factory()->started()->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/user/cognitive-events');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_events_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/user/cognitive-events');
        $response->assertStatus(401);
    }

    public function test_unversioned_endpoint_is_not_registered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/user/cognitive-phase')->assertStatus(404);
        $this->actingAs($user)->getJson('/api/user/cognitive-events')->assertStatus(404);
    }
}
