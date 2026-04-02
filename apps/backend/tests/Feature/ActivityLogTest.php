<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_returns_cursor_paginated_items(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        Item::factory()->count(5)->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'status',
                        'is_assigned',
                        'is_assigned_to_me',
                        'is_delegated',
                        'project',
                        'assignee',
                        'owner',
                        'completed_by',
                        'activity_at',
                        'completed_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'path',
                    'per_page',
                    'next_cursor',
                    'prev_cursor',
                ],
            ]);
    }

    public function test_activity_log_orders_by_actual_activity_time_descending(): void
    {
        $user = User::factory()->create();

        $doneItem = Item::factory()->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => Carbon::parse('2026-03-09 11:00:00'),
            'updated_at' => Carbon::parse('2026-03-09 11:00:00'),
        ]);

        $wontdoItem = Item::factory()->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'wontdo',
            'completed_at' => Carbon::parse('2026-03-01 08:00:00'),
            'updated_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($doneItem->id, $data[0]['id']);
        $this->assertEquals($wontdoItem->id, $data[1]['id']);
        $this->assertSame($wontdoItem->completed_at->toIso8601String(), $data[1]['completed_at']);
    }

    public function test_activity_log_respects_per_page_parameter(): void
    {
        $user = User::factory()->create();

        Item::factory()->count(10)->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log?per_page=3');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_activity_log_caps_per_page_at_50(): void
    {
        $user = User::factory()->create();

        Item::factory()->count(5)->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log?per_page=100');

        $response->assertStatus(200);
        $this->assertEquals(50, $response->json('meta.per_page'));
    }

    public function test_activity_log_only_includes_completed_or_cancelled_accessible_items(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $ownedDone = Item::factory()->count(2)->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        Item::factory()->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'todo',
        ]);

        $assignedToUser = Item::factory()->create([
            'user_id' => $other->id,
            'assignee_id' => $user->id,
            'project_id' => null,
            'status' => 'wontdo',
            'completed_at' => now(),
        ]);

        Item::factory()->count(2)->create([
            'user_id' => $other->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $ownedDone[0]->id])
            ->assertJsonFragment(['id' => $assignedToUser->id]);

        $this->assertNotContains('todo', array_column($response->json('data'), 'status'));
    }

    public function test_activity_log_supports_cursor_pagination(): void
    {
        $user = User::factory()->create();

        // Create items with distinct timestamps for stable cursor ordering
        for ($i = 0; $i < 5; $i++) {
            Item::factory()->create([
                'user_id' => $user->id,
                'project_id' => null,
                'status' => $i % 2 === 0 ? 'done' : 'wontdo',
                'completed_at' => now()->subMinutes(5 - $i),
                'updated_at' => now()->subMinutes(5 - $i),
            ]);
        }

        // Get first page
        $firstPage = $this->actingAs($user)
            ->getJson('/api/activity-log?per_page=2');

        $firstPage->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $nextCursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        // Get second page using cursor
        $secondPage = $this->actingAs($user)
            ->getJson('/api/activity-log?per_page=2&cursor='.urlencode($nextCursor));

        $secondPage->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Ensure no overlap
        $firstIds = array_column($firstPage->json('data'), 'id');
        $secondIds = array_column($secondPage->json('data'), 'id');
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
    }

    public function test_activity_log_requires_authentication(): void
    {
        $response = $this->getJson('/api/activity-log');

        $response->assertStatus(401);
    }

    public function test_activity_log_prefers_cancellation_timestamp_when_no_audit_log_exists(): void
    {
        $user = User::factory()->create();

        $item = Item::factory()->create([
            'user_id' => $user->id,
            'project_id' => null,
            'status' => 'wontdo',
            'completed_at' => Carbon::parse('2026-03-01 08:00:00'),
            'updated_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        AuditLog::query()
            ->where('resource_type', 'item')
            ->where('resource_id', $item->id)
            ->delete();

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $item->id,
                'activity_at' => '2026-03-01T08:00:00+00:00',
            ]);
    }

    public function test_activity_log_includes_assignment_context_and_actor(): void
    {
        $user = User::factory()->create(['name' => 'Owner User']);
        $delegate = User::factory()->create(['name' => 'Delegate User']);
        $manager = User::factory()->create(['name' => 'Manager User']);

        $delegatedItem = Item::factory()->create([
            'user_id' => $user->id,
            'assignee_id' => $delegate->id,
            'project_id' => null,
            'status' => 'done',
            'completed_at' => now()->subHour(),
        ]);

        $assignedToUser = Item::factory()->create([
            'user_id' => $manager->id,
            'assignee_id' => $user->id,
            'project_id' => null,
            'status' => 'wontdo',
            'completed_at' => now()->subMinutes(30),
        ]);

        AuditLog::create([
            'user_id' => $delegate->id,
            'action' => 'item_updated',
            'resource_type' => 'item',
            'resource_id' => $delegatedItem->id,
            'status' => 'success',
            'metadata' => [
                'after' => ['status' => 'done'],
            ],
        ]);

        AuditLog::create([
            'user_id' => $manager->id,
            'action' => 'item_updated',
            'resource_type' => 'item',
            'resource_id' => $assignedToUser->id,
            'status' => 'success',
            'metadata' => [
                'after' => ['status' => 'wontdo'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/activity-log');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $delegatedItem->id,
                'is_delegated' => true,
                'is_assigned_to_me' => false,
            ])
            ->assertJsonFragment([
                'id' => $assignedToUser->id,
                'is_delegated' => false,
                'is_assigned_to_me' => true,
            ])
            ->assertJsonFragment([
                'name' => 'Delegate User',
            ])
            ->assertJsonFragment([
                'name' => 'Manager User',
            ]);
    }
}
