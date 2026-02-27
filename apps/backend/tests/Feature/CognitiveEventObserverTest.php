<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CognitiveEventType;
use App\Jobs\RecalibrateCognitivePhaseJob;
use App\Models\CognitiveEvent;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class CognitiveEventObserverTest extends TestCase
{
    use RefreshDatabase;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_completing_item_logs_cognitive_event(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['status' => 'done']);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'event_type' => CognitiveEventType::Completed->value,
        ]);
    }

    public function test_starting_item_logs_started_event(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['status' => 'doing']);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'event_type' => CognitiveEventType::Started->value,
        ]);
    }

    public function test_event_uses_item_cognitive_load(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->withCognitiveLoad(8)->create(['user_id' => $user->id]);

        $item->update(['status' => 'done']);

        $this->assertDatabaseHas('cognitive_events', [
            'item_id' => $item->id,
            'cognitive_load_score' => 8,
        ]);
    }

    public function test_event_defaults_load_to_5_when_null(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load' => null,
        ]);

        $item->update(['status' => 'done']);

        $this->assertDatabaseHas('cognitive_events', [
            'item_id' => $item->id,
            'cognitive_load_score' => 5,
        ]);
    }

    public function test_reverting_from_done_does_not_log(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->done()->create(['user_id' => $user->id]);

        // Clear the event that may have been logged on creation
        CognitiveEvent::query()->delete();

        $item->update(['status' => 'todo']);

        $this->assertDatabaseCount('cognitive_events', 0);
    }

    public function test_completing_item_dispatches_recalibrate_job(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['status' => 'done']);

        Bus::assertDispatched(
            RecalibrateCognitivePhaseJob::class,
            fn (RecalibrateCognitivePhaseJob $job): bool => $job->userId === (string) $user->id,
        );
    }

    /**
     * CRITICAL: This proves standard CRUD is entirely unaffected.
     */
    public function test_updating_title_only_does_not_log_event(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['title' => 'Renamed Task']);

        $this->assertDatabaseCount('cognitive_events', 0);
        Bus::assertNotDispatched(RecalibrateCognitivePhaseJob::class);
    }

    public function test_updating_description_does_not_log_event(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['description' => 'New description']);

        $this->assertDatabaseCount('cognitive_events', 0);
    }

    public function test_creating_todo_item_does_not_log_event(): void
    {
        $user = User::factory()->create();

        Item::factory()->todo()->create(['user_id' => $user->id]);

        $this->assertDatabaseCount('cognitive_events', 0);
    }

    public function test_observer_logs_for_owner_not_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $item = Item::factory()->todo()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $item->update(['status' => 'done']);

        $this->assertDatabaseHas('cognitive_events', [
            'user_id' => $owner->id,
            'item_id' => $item->id,
        ]);
        $this->assertDatabaseMissing('cognitive_events', [
            'user_id' => $assignee->id,
        ]);
    }

    public function test_occurred_at_has_microsecond_precision(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['status' => 'done']);

        $event = CognitiveEvent::where('item_id', $item->id)->first();
        $this->assertNotNull($event);

        // The cast format is 'Y-m-d H:i:s.u' — the occurred_at must round-trip with a fractional part.
        $formatted = $event->occurred_at->format('Y-m-d H:i:s.u');
        $this->assertMatchesRegularExpression('/\.\d{6}$/', $formatted);
    }

    public function test_starting_item_does_not_dispatch_recalibrate(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create(['user_id' => $user->id]);

        $item->update(['status' => 'doing']);

        Bus::assertNotDispatched(RecalibrateCognitivePhaseJob::class);
    }

    /**
     * Dead-code guard: every CognitiveEventType case must be emitted by at
     * least one real status transition.
     *
     * This test exists because the original implementation shipped a
     * `Focused` case no transition ever wrote — PHPUnit can't see unused
     * enum cases. Rather than hand-maintaining a status→event mapping
     * (which is just a second place to get out of sync), this test drives
     * the *entire* status-transition space through the real observer and
     * collects whatever event types actually land in the database. If the
     * collected set is a proper subset of the enum, you have dead cases.
     *
     * Zero duplication: when you wire a new transition in ItemObserver,
     * this test picks it up automatically. When you add a new enum case
     * without a producer, this test fails with the offending case name.
     */
    public function test_every_event_type_is_emitted_by_some_status_transition(): void
    {
        $user = User::factory()->create();

        // Enumerate the complete status space. Using the Rule::in list from
        // StoreItemRequest would be tighter, but that couples tests across
        // modules — keeping the list literal and co-located is clearer.
        $statuses = ['todo', 'doing', 'done', 'wontdo'];

        // Exercise every possible (create-status, update-status) pair
        // through the real observer. Collect the distinct event types
        // that actually get written.
        $emitted = [];

        foreach ($statuses as $initial) {
            // Creation path
            Item::factory()->create([
                'user_id' => $user->id,
                'status' => $initial,
            ]);

            // Update path (from each initial to every other status)
            foreach ($statuses as $target) {
                if ($initial === $target) {
                    continue;
                }
                Item::factory()->create([
                    'user_id' => $user->id,
                    'status' => $initial,
                ])->update(['status' => $target]);
            }
        }

        $emitted = CognitiveEvent::query()
            ->distinct()
            ->pluck('event_type')
            ->all();

        // Every enum case must appear in what the observer actually emitted.
        foreach (CognitiveEventType::cases() as $case) {
            $this->assertContains(
                $case->value,
                $emitted,
                sprintf(
                    "CognitiveEventType::%s ('%s') is never emitted by ItemObserver. "
                    .'Drove all %d×%d status transitions (%s); observer only produced: [%s]. '
                    .'Either wire up a status transition in ItemObserver that writes this '
                    .'event type, or delete the dead case.',
                    $case->name,
                    $case->value,
                    count($statuses),
                    count($statuses),
                    implode(', ', $statuses),
                    implode(', ', $emitted),
                ),
            );
        }

        // Sanity: the observer did emit *something* (guards against a
        // silently-disabled observer making the test pass vacuously).
        $this->assertNotEmpty(
            $emitted,
            'ItemObserver emitted no cognitive events at all. '
            .'Observer may be unregistered — check AppServiceProvider::boot().',
        );
    }
}
