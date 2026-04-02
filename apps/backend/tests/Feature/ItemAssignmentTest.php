<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ItemAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a mutual connection between two users.
     */
    private function createConnection(User $user1, User $user2): void
    {
        Connection::create([
            'requester_id' => $user1->id,
            'addressee_id' => $user2->id,
            'status' => 'accepted',
        ]);
    }

    public function test_owner_can_assign_item_to_connected_user(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => $assignee->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'assignee_id' => $assignee->id,
                'is_assigned' => true,
                'is_delegated' => true,
            ]);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'assignee_id' => $assignee->id,
        ]);
    }

    public function test_assigning_to_unconnected_user_auto_creates_connection(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        // No connection created

        $item = Item::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => $stranger->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'assignee_id' => $stranger->id,
        ]);
        // Verify connection was auto-created
        $this->assertTrue($owner->isConnectedWith((string) $stranger->id));
    }

    public function test_creating_item_with_unconnected_assignee_auto_creates_connection(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        // No connection created

        $response = $this->actingAs($owner)
            ->postJson('/api/items', [
                'title' => 'Assigned Task',
                'status' => 'todo',
                'assignee_id' => $stranger->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('items', [
            'title' => 'Assigned Task',
            'assignee_id' => $stranger->id,
        ]);
        // Verify connection was auto-created
        $this->assertTrue($owner->isConnectedWith((string) $stranger->id));
    }

    public function test_assignee_can_view_assigned_item(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $response = $this->actingAs($assignee)
            ->getJson("/api/items/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $item->id,
            ]);
    }

    public function test_assignee_can_update_item_status(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'doing',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => 'doing',
            ]);
    }

    public function test_assignee_cannot_delete_item(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $response = $this->actingAs($assignee)
            ->deleteJson("/api/items/{$item->id}");

        $response->assertStatus(403);
    }

    public function test_owner_can_reassign_item(): void
    {
        $owner = User::factory()->create();
        $assignee1 = User::factory()->create();
        $assignee2 = User::factory()->create();
        $this->createConnection($owner, $assignee1);
        $this->createConnection($owner, $assignee2);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee1->id,
        ]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => $assignee2->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'assignee_id' => $assignee2->id,
            ]);
    }

    public function test_owner_can_unassign_item(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'assignee_id' => null,
                'is_assigned' => false,
            ]);
    }

    public function test_unassigning_creates_notification(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'My Task',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_unassigned',
        ]);
    }

    public function test_completing_task_notifies_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'My Task',
            'status' => 'todo',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'done',
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_completed',
        ]);
    }

    public function test_updating_task_title_notifies_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Original Title',
            'status' => 'todo',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'title' => 'Updated Title',
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_updated',
        ]);
    }

    public function test_items_api_filters_assigned_to_me(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $ownedItem = Item::factory()->create([
            'user_id' => $assignee->id,
            'status' => 'todo',
        ]);
        $assignedItem = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($assignee)
            ->getJson('/api/items?assigned_to_me=true');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $assignedItem->id])
            ->assertJsonMissing(['id' => $ownedItem->id]);
    }

    public function test_items_api_filters_delegated(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $unassignedItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'todo',
        ]);
        $delegatedItem = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/items?delegated=true');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $delegatedItem->id])
            ->assertJsonMissing(['id' => $unassignedItem->id]);
    }

    public function test_default_items_api_excludes_assigned_items(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $unassignedItem = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => null,
            'status' => 'todo',
        ]);
        $delegatedItem = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/items');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $unassignedItem->id])
            ->assertJsonMissing(['id' => $delegatedItem->id]);
    }

    public function test_cannot_assign_to_nonexistent_user(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => '00000000-0000-0000-0000-000000000000',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_id']);
    }

    public function test_create_item_with_connected_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $response = $this->actingAs($owner)
            ->postJson('/api/items', [
                'title' => 'Assigned Task',
                'status' => 'todo',
                'assignee_id' => $assignee->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'title' => 'Assigned Task',
                'assignee_id' => $assignee->id,
                'is_assigned' => true,
            ]);
    }

    public function test_pending_connection_is_auto_accepted_on_assignment(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        // Create pending (not accepted) connection
        $connection = Connection::create([
            'requester_id' => $owner->id,
            'addressee_id' => $stranger->id,
            'status' => 'pending',
        ]);

        $item = Item::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => $stranger->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'assignee_id' => $stranger->id,
        ]);
        // Verify the pending connection was auto-accepted
        $connection->refresh();
        $this->assertEquals('accepted', $connection->status);
    }

    public function test_assignee_can_only_update_status_description_and_notes(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Original Title',
            'status' => 'todo',
        ]);

        // Assignee should be able to update status
        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'doing',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'doing']);

        // Assignee should be able to update assignee_notes
        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_notes' => 'Working on this now',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['assignee_notes' => 'Working on this now']);
    }

    public function test_assignee_cannot_update_title(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Original Title',
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'title' => 'Changed Title',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Assignees can only update status, description, and notes.');
    }

    public function test_assignee_can_update_description(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'description' => 'Changed description',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'description' => 'Changed description',
        ]);
    }

    public function test_assignee_cannot_reassign_item(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $anotherUser = User::factory()->create();
        $this->createConnection($owner, $assignee);
        $this->createConnection($owner, $anotherUser);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => $anotherUser->id,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Assignees can only update status, description, and notes.');
    }

    public function test_owner_can_update_any_field(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Original Title',
            'description' => 'Original description',
        ]);

        // Owner should be able to update title
        $response = $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'title' => 'Changed Title',
                'description' => 'Changed description',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'title' => 'Changed Title',
                'description' => 'Changed description',
            ]);
    }

    public function test_assignee_cannot_reorder_items(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'position' => 0,
        ]);

        $response = $this->actingAs($assignee)
            ->postJson('/api/items/reorder', [
                'items' => [
                    ['id' => $item->id, 'position' => 5],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_reorder_items(): void
    {
        $owner = User::factory()->create();

        $item1 = Item::factory()->todo()->create([
            'user_id' => $owner->id,
            'position' => 0,
        ]);
        $item2 = Item::factory()->todo()->create([
            'user_id' => $owner->id,
            'position' => 1,
        ]);

        $response = $this->actingAs($owner)
            ->postJson('/api/items/reorder', [
                'items' => [
                    ['id' => $item1->id, 'position' => 1],
                    ['id' => $item2->id, 'position' => 0],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertEquals(1, $item1->fresh()->position);
        $this->assertEquals(0, $item2->fresh()->position);
    }

    public function test_items_api_excludes_completed_by_default(): void
    {
        $owner = User::factory()->create();

        $todoItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'todo',
        ]);
        $doneItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'done',
        ]);
        $wontdoItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'wontdo',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/items');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $todoItem->id])
            ->assertJsonMissing(['id' => $doneItem->id])
            ->assertJsonMissing(['id' => $wontdoItem->id]);
    }

    public function test_items_api_includes_completed_when_requested(): void
    {
        $owner = User::factory()->create();

        $todoItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'todo',
        ]);
        $doneItem = Item::factory()->create([
            'user_id' => $owner->id,
            'status' => 'done',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/items?include_completed=true');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $todoItem->id])
            ->assertJsonFragment(['id' => $doneItem->id]);
    }

    public function test_assigned_to_me_excludes_completed_by_default(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $todoAssigned = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);
        $doneAssigned = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'done',
        ]);

        $response = $this->actingAs($assignee)
            ->getJson('/api/items?assigned_to_me=true');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $todoAssigned->id])
            ->assertJsonMissing(['id' => $doneAssigned->id]);
    }

    public function test_delegated_excludes_completed_by_default(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $todoDelegated = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);
        $doneDelegated = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'done',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/items?delegated=true');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $todoDelegated->id])
            ->assertJsonMissing(['id' => $doneDelegated->id]);
    }

    // ─── Story: "The Loop" — Assignee completes task, creator notified ──────────

    public function test_assignee_completing_task_notifies_creator(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Write report',
            'status' => 'todo',
        ]);

        $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'done',
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'task_completed_by_assignee',
        ]);

        // Verify the notification message contains the task title
        $notification = \App\Models\Notification::where('user_id', $owner->id)
            ->where('type', 'task_completed_by_assignee')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Write report', $notification->message);
        $this->assertStringContainsString($assignee->name, $notification->message);
        $this->assertStringContainsString('completed', $notification->message);
    }

    public function test_assignee_wontdo_task_notifies_creator(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Optional task',
            'status' => 'todo',
        ]);

        $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'wontdo',
            ]);

        $notification = \App\Models\Notification::where('user_id', $owner->id)
            ->where('type', 'task_completed_by_assignee')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString("marked as won't do", $notification->message);
        $this->assertStringContainsString('Optional task', $notification->message);
    }

    public function test_assignee_completing_own_task_does_not_notify(): void
    {
        $user = User::factory()->create();

        $item = Item::factory()->create([
            'user_id' => $user->id,
            'assignee_id' => null,
            'status' => 'todo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'done',
            ]);

        $this->assertDatabaseMissing('notifications', [
            'type' => 'task_completed_by_assignee',
        ]);
    }

    public function test_owner_completing_delegated_task_does_not_double_notify_creator(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Delegated task',
            'status' => 'todo',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'status' => 'done',
            ]);

        // Owner completes → only task_completed (to assignee) should exist
        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_completed',
        ]);

        // No task_completed_by_assignee notification should exist (owner did it, not assignee)
        $this->assertDatabaseMissing('notifications', [
            'type' => 'task_completed_by_assignee',
        ]);
    }

    // ─── Story: Due Date Change Notifications ───────────────────────────────────

    public function test_owner_changing_due_date_notifies_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Review PR',
            'status' => 'todo',
            'due_date' => '2026-03-01',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'due_date' => '2026-02-15',
            ]);

        $notification = \App\Models\Notification::where('user_id', $assignee->id)
            ->where('type', 'task_updated')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Review PR', $notification->message);

        // Verify due_date is in the changes data
        $data = $notification->data;
        $this->assertArrayHasKey('changes', $data);
        $this->assertArrayHasKey('due_date', $data['changes']);
        $this->assertEquals('2026-03-01', $data['changes']['due_date']['from']);
        $this->assertEquals('2026-02-15', $data['changes']['due_date']['to']);
    }

    public function test_owner_clearing_due_date_notifies_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Flexible task',
            'status' => 'todo',
            'due_date' => '2026-03-01',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'due_date' => null,
            ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_updated',
        ]);
    }

    public function test_owner_changing_due_date_on_unassigned_task_does_not_notify(): void
    {
        $owner = User::factory()->create();

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => null,
            'status' => 'todo',
            'due_date' => '2026-03-01',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/items/{$item->id}", [
                'due_date' => '2026-02-15',
            ]);

        $this->assertDatabaseMissing('notifications', [
            'type' => 'task_updated',
        ]);
    }

    // ─── Story: Assignment Rejection ────────────────────────────────────────────

    public function test_assignee_can_unassign_themselves(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'assignee_id' => null,
                'is_assigned' => false,
            ]);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'assignee_id' => null,
        ]);
    }

    public function test_assignee_self_unassignment_notifies_creator(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Rejected task',
            'status' => 'todo',
        ]);

        $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
            ]);

        $notification = \App\Models\Notification::where('user_id', $owner->id)
            ->where('type', 'task_rejected')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString($assignee->name, $notification->message);
        $this->assertStringContainsString('Rejected task', $notification->message);
        $this->assertStringContainsString('declined', $notification->message);
    }

    public function test_assignee_self_unassignment_does_not_send_unassigned_notification(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
            ]);

        // task_rejected should exist (sent to owner)
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'task_rejected',
        ]);

        // task_unassigned should NOT exist (that's only for owner-initiated unassignment)
        $this->assertDatabaseMissing('notifications', [
            'type' => 'task_unassigned',
        ]);
    }

    public function test_assignee_can_unassign_and_update_status_together(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => 'todo',
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
                'status' => 'wontdo',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'assignee_id' => null,
                'status' => 'wontdo',
            ]);
    }

    public function test_assignee_cannot_unassign_and_change_title_together(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $this->createConnection($owner, $assignee);

        $item = Item::factory()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'title' => 'Original Title',
        ]);

        $response = $this->actingAs($assignee)
            ->patchJson("/api/items/{$item->id}", [
                'assignee_id' => null,
                'title' => 'Changed Title',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Assignees can only update status, description, and notes.');
    }
}
