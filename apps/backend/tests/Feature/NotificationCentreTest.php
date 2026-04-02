<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_dismiss_own_notification(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Dismissible',
            'message' => 'This can be dismissed',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Notification dismissed.',
            ]);

        $this->assertDatabaseMissing('notifications', [
            'id' => $notification->id,
        ]);
    }

    public function test_user_cannot_dismiss_other_users_notification(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user1->id,
            'type' => 'task_assigned',
            'title' => 'Not yours',
            'message' => 'This belongs to user 1',
        ]);

        $response = $this->actingAs($user2)
            ->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
        ]);
    }

    public function test_dismiss_returns_updated_unread_count(): void
    {
        $user = User::factory()->create();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Notification 1',
            'message' => 'Unread 1',
        ]);

        $toDismiss = Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Notification 2',
            'message' => 'Unread 2',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/notifications/{$toDismiss->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'unread_count' => 1,
            ]);
    }

    public function test_mark_as_read_returns_correct_unread_count(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Read me',
            'message' => 'Mark as read test',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Other',
            'message' => 'Still unread',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'unread_count' => 1,
            ]);
    }

    public function test_notifications_include_human_readable_timestamp(): void
    {
        $user = User::factory()->create();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Recent',
            'message' => 'Just now notification',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/notifications');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('created_at_human', $data[0]);
    }

    public function test_dismiss_requires_authentication(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'title' => 'Auth test',
            'message' => 'Requires auth',
        ]);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(401);
    }
}
