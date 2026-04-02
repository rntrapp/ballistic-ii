<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Database\Seeders\LocalTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class LocalTestingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_testing_seeder_creates_repeatable_demo_data(): void
    {
        Carbon::setTestNow('2026-03-21 09:00:00');

        try {
            $this->seed(LocalTestingSeeder::class);
            $this->seed(LocalTestingSeeder::class);

            $owner = User::query()->where('email', 'local@example.com')->firstOrFail();
            $assignee = User::query()->where('email', 'alex.collaborator@example.com')->firstOrFail();

            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseHas('projects', [
                'user_id' => $owner->id,
                'name' => 'Launch Prep',
            ]);
            $this->assertDatabaseHas('projects', [
                'user_id' => $owner->id,
                'name' => 'Personal Ops',
            ]);
            $this->assertDatabaseHas('connections', [
                'requester_id' => $owner->id,
                'addressee_id' => $assignee->id,
                'status' => 'accepted',
            ]);

            $this->assertSame(8, Item::query()->where('user_id', $owner->id)->count());

            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Inbox capture: chase supplier quote',
                'status' => 'todo',
                'project_id' => null,
            ]);
            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Polish onboarding flow copy',
                'status' => 'doing',
            ]);
            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Invoice March hosting costs',
                'status' => 'done',
            ]);
            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Cancel outdated venue shortlist',
                'status' => 'wontdo',
            ]);
            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Recurring: weekly ops review',
                'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
                'recurrence_strategy' => 'carry_over',
            ]);
            $this->assertDatabaseHas('items', [
                'user_id' => $owner->id,
                'title' => 'Delegated copy pass for launch email',
                'assignee_id' => $assignee->id,
                'status' => 'doing',
            ]);

            $overdueItem = Item::query()
                ->where('user_id', $owner->id)
                ->where('title', 'Follow up overdue sponsor approval')
                ->firstOrFail();
            $scheduledItem = Item::query()
                ->where('user_id', $owner->id)
                ->where('title', 'Prep next sprint planning agenda')
                ->firstOrFail();

            $this->assertSame('2026-03-20', $overdueItem->due_date?->toDateString());
            $this->assertSame('2026-03-24', $scheduledItem->scheduled_date?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }
}
