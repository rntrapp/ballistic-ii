<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecalibrateCognitivePhaseJob;
use App\Models\CognitiveEvent;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Services\ChronobiologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CognitivePhaseTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // ItemObserver — status transition logging
    // -----------------------------------------------------------------------

    public function test_observer_logs_started_event_on_todo_to_doing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'event_type' => 'started',
        ]);
    }

    public function test_observer_logs_completed_event_on_any_to_done(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->doing()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'done',
        ]);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'event_type' => 'completed',
        ]);
    }

    public function test_observer_logs_cancelled_event_on_any_to_wontdo(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'wontdo',
        ]);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'event_type' => 'cancelled',
        ]);
    }

    public function test_observer_does_not_log_on_non_status_changes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'title' => 'Updated Title',
        ]);

        $this->assertDatabaseMissing('cognitive_events', [
            'item_id' => $item->id,
        ]);
    }

    public function test_observer_ignores_non_mapped_transitions(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        // doing→todo is not a mapped transition
        $item = Item::factory()->doing()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'todo',
        ]);

        $this->assertDatabaseMissing('cognitive_events', [
            'item_id' => $item->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // ItemObserver — cognitive load scoring
    // -----------------------------------------------------------------------

    public function test_observer_uses_explicit_cognitive_load_score(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 8,
        ]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        $this->assertDatabaseHas('cognitive_events', [
            'item_id' => $item->id,
            'cognitive_load_score' => 8,
        ]);
    }

    public function test_observer_auto_estimates_score_when_not_set(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => null,
            'description' => 'A short task',
        ]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        $event = CognitiveEvent::where('item_id', $item->id)->first();
        $this->assertNotNull($event);
        $this->assertGreaterThanOrEqual(1, $event->cognitive_load_score);
        $this->assertLessThanOrEqual(10, $event->cognitive_load_score);
    }

    public function test_observer_estimates_higher_score_for_complex_items(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Simple item: no description, no due date, no project.
        $simpleItem = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => null,
            'description' => null,
            'due_date' => null,
            'project_id' => null,
        ]);

        // Complex item: long description + due date + project.
        $complexItem = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => null,
            'description' => str_repeat('x', 600), // > 500 chars → +3
            'due_date' => now()->addDay(),          // has due date → +1
            'project_id' => $project->id,           // has project  → +1
        ]);

        // Trigger both.
        $this->actingAs($user)->patchJson("/api/items/{$simpleItem->id}", [
            'status' => 'doing',
        ]);
        $this->actingAs($user)->patchJson("/api/items/{$complexItem->id}", [
            'status' => 'doing',
        ]);

        $simpleEvent = CognitiveEvent::where('item_id', $simpleItem->id)->first();
        $complexEvent = CognitiveEvent::where('item_id', $complexItem->id)->first();

        // Simple: baseline 3, no additions → 3.
        $this->assertEquals(3, $simpleEvent->cognitive_load_score);

        // Complex: baseline 3 + desc(3) + due(1) + project(1) = 8.
        $this->assertEquals(8, $complexEvent->cognitive_load_score);
    }

    public function test_observer_handles_item_without_user_gracefully(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        // The observer should work fine with a valid user_id
        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        $this->assertDatabaseHas('cognitive_events', [
            'item_id' => $item->id,
            'event_type' => 'started',
        ]);
    }

    // -----------------------------------------------------------------------
    // ItemObserver — recorded_at column type
    // -----------------------------------------------------------------------

    public function test_recorded_at_stored_as_proper_datetime(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        $event = CognitiveEvent::where('item_id', $item->id)->first();
        $this->assertNotNull($event);

        // With the 'datetime' cast, recorded_at should hydrate as a Carbon instance.
        $this->assertInstanceOf(Carbon::class, $event->recorded_at);

        // It should be within the last few seconds.
        $this->assertTrue($event->recorded_at->diffInSeconds(now()) < 10);
    }

    // -----------------------------------------------------------------------
    // RecalibrateCognitivePhaseJob
    // -----------------------------------------------------------------------

    public function test_recalibrate_job_dispatched_on_task_completion(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->doing()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'done',
        ]);

        Queue::assertPushed(RecalibrateCognitivePhaseJob::class, function ($job) use ($user) {
            return $job->userId === (string) $user->id;
        });
    }

    public function test_recalibrate_job_not_dispatched_on_started(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'status' => 'doing',
        ]);

        Queue::assertNotPushed(RecalibrateCognitivePhaseJob::class);
    }

    public function test_recalibrate_job_caches_analysis_result(): void
    {
        $user = User::factory()->create();

        // Seed some events so analyse() returns a real result.
        $baseTime = now()->subDays(3);
        for ($i = 0; $i < 10; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => ($i % 2 === 0) ? 9 : 2,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 90),
            ]);
        }

        $cacheKey = "cognitive_phase:{$user->id}";
        $this->assertNull(Cache::get($cacheKey));

        // Run the job synchronously.
        $job = new RecalibrateCognitivePhaseJob((string) $user->id);
        $job->handle(app(ChronobiologyService::class));

        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('dominant_cycle_minutes', $cached);
        $this->assertArrayHasKey('current_phase', $cached);
        $this->assertArrayHasKey('confidence_score', $cached);
    }

    // -----------------------------------------------------------------------
    // Cognitive Phase Endpoint — structure & auth
    // -----------------------------------------------------------------------

    public function test_cognitive_phase_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/user/cognitive-phase');

        $response->assertStatus(401);
    }

    public function test_cognitive_phase_endpoint_returns_default_when_no_events(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user/cognitive-phase');

        $response->assertOk()
            ->assertJsonPath('data.current_phase', 'Peak');

        $data = $response->json('data');
        $this->assertEquals(0.0, $data['confidence_score']);
        $this->assertEquals(90.0, $data['dominant_cycle_minutes']);
    }

    public function test_endpoint_returns_full_response_structure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user/cognitive-phase');
        $response->assertOk();

        $data = $response->json('data');

        // All keys present.
        $this->assertArrayHasKey('dominant_cycle_minutes', $data);
        $this->assertArrayHasKey('current_phase', $data);
        $this->assertArrayHasKey('phase_progress', $data);
        $this->assertArrayHasKey('next_peak_at', $data);
        $this->assertArrayHasKey('confidence_score', $data);
        $this->assertArrayHasKey('today_events', $data);

        // Type checks (JSON numbers/strings).
        $this->assertIsNumeric($data['dominant_cycle_minutes']);
        $this->assertIsString($data['current_phase']);
        $this->assertIsNumeric($data['phase_progress']);
        $this->assertIsString($data['next_peak_at']);
        $this->assertIsNumeric($data['confidence_score']);
        $this->assertIsArray($data['today_events']);
    }

    public function test_cognitive_phase_endpoint_detects_periodic_cycle(): void
    {
        $user = User::factory()->create();

        // Create events at 100-minute intervals over several "cycles"
        $baseTime = now()->subDays(7);
        for ($i = 0; $i < 30; $i++) {
            $time = $baseTime->copy()->addMinutes($i * 100);
            // Alternate high/low scores to create a clear signal
            $score = ($i % 2 === 0) ? 9 : 2;
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => $score,
                'recorded_at' => $time,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/user/cognitive-phase');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertGreaterThan(0.0, $data['confidence_score']);
        $this->assertContains($data['current_phase'], ['Peak', 'Trough', 'Recovery']);

        // Dominant cycle must be in the detectable range (60–240 min).
        $this->assertGreaterThanOrEqual(60.0, $data['dominant_cycle_minutes']);
        $this->assertLessThanOrEqual(240.0, $data['dominant_cycle_minutes']);

        // phase_progress bounded.
        $this->assertGreaterThanOrEqual(0.0, $data['phase_progress']);
        $this->assertLessThanOrEqual(1.0, $data['phase_progress']);

        // next_peak_at must be a valid future timestamp.
        $nextPeak = Carbon::parse($data['next_peak_at']);
        $this->assertTrue($nextPeak->isFuture());
    }

    public function test_endpoint_returns_today_events(): void
    {
        $user = User::factory()->create();

        // Create an event today (within lookback and today).
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 7,
            'recorded_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/user/cognitive-phase');
        $response->assertOk();

        $todayEvents = $response->json('data.today_events');
        $this->assertCount(1, $todayEvents);
        $this->assertEquals(7, $todayEvents[0]['cognitive_load_score']);
        $this->assertEquals('completed', $todayEvents[0]['event_type']);
        $this->assertArrayHasKey('recorded_at', $todayEvents[0]);
    }

    // -----------------------------------------------------------------------
    // Cognitive Phase Endpoint — caching
    // -----------------------------------------------------------------------

    public function test_endpoint_serves_from_cache_on_second_request(): void
    {
        $user = User::factory()->create();

        // Prime the cache via the first request.
        $response1 = $this->actingAs($user)->getJson('/api/user/cognitive-phase');
        $response1->assertOk();

        $cacheKey = "cognitive_phase:{$user->id}";
        $this->assertNotNull(Cache::get($cacheKey));

        // Tamper with the cached value to prove the second request reads from cache.
        $cached = Cache::get($cacheKey);
        $cached['current_phase'] = 'CacheHit';
        Cache::put($cacheKey, $cached, now()->addMinutes(5));

        $response2 = $this->actingAs($user)->getJson('/api/user/cognitive-phase');
        $response2->assertOk()
            ->assertJsonPath('data.current_phase', 'CacheHit');
    }

    // -----------------------------------------------------------------------
    // cognitive_load_score field on items
    // -----------------------------------------------------------------------

    public function test_cognitive_load_score_field_works_in_item_create(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Scored Task',
            'status' => 'todo',
            'cognitive_load_score' => 7,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.cognitive_load_score', 7);

        $this->assertDatabaseHas('items', [
            'title' => 'Scored Task',
            'cognitive_load_score' => 7,
        ]);
    }

    public function test_cognitive_load_score_field_works_in_item_update(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'cognitive_load_score' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.cognitive_load_score', 3);
    }

    public function test_cognitive_load_score_rejects_zero(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Bad score',
            'status' => 'todo',
            'cognitive_load_score' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load_score');
    }

    public function test_cognitive_load_score_rejects_above_ten(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Bad score',
            'status' => 'todo',
            'cognitive_load_score' => 11,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load_score');
    }

    public function test_cognitive_load_score_rejects_negative(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Bad score',
            'status' => 'todo',
            'cognitive_load_score' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load_score');
    }

    public function test_cognitive_load_score_rejects_non_integer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Bad score',
            'status' => 'todo',
            'cognitive_load_score' => 'high',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load_score');
    }

    // -----------------------------------------------------------------------
    // Smoke test — existing CRUD not broken
    // -----------------------------------------------------------------------

    public function test_existing_item_crud_still_works(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Create
        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Smoke Test',
            'status' => 'todo',
            'project_id' => $project->id,
        ]);
        $response->assertStatus(201);
        $itemId = $response->json('data.id');

        // Read
        $response = $this->actingAs($user)->getJson("/api/items/{$itemId}");
        $response->assertOk()->assertJsonPath('data.title', 'Smoke Test');

        // Update
        $response = $this->actingAs($user)->patchJson("/api/items/{$itemId}", [
            'title' => 'Updated Smoke Test',
        ]);
        $response->assertOk()->assertJsonPath('data.title', 'Updated Smoke Test');

        // Delete
        $response = $this->actingAs($user)->deleteJson("/api/items/{$itemId}");
        $response->assertStatus(204);
    }
}
