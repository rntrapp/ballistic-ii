<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Auth\TokenAbility;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    // ── Model CRUD audit logging ─────────────────────────────────

    public function test_item_created_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/items', [
            'title' => 'Test item for audit',
            'status' => 'todo',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'item_created',
            'resource_type' => 'item',
        ]);
    }

    public function test_item_updated_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->create(['title' => 'Original']);
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->putJson("/api/items/{$item->id}", [
            'title' => 'Updated title',
        ]);

        $log = AuditLog::where('action', 'item_updated')
            ->where('resource_id', $item->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Original', $log->metadata['before']['title'] ?? null);
        $this->assertEquals('Updated title', $log->metadata['after']['title'] ?? null);
    }

    public function test_item_deleted_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->deleteJson("/api/items/{$item->id}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'item_deleted',
            'resource_type' => 'item',
            'resource_id' => $item->id,
        ]);
    }

    public function test_project_created_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->postJson('/api/projects', [
            'name' => 'Test project',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project_created',
            'resource_type' => 'project',
        ]);
    }

    public function test_project_updated_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Old name']);
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->putJson("/api/projects/{$project->id}", [
            'name' => 'New name',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project_updated',
            'resource_type' => 'project',
            'resource_id' => $project->id,
        ]);
    }

    public function test_tag_created_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->postJson('/api/tags', [
            'name' => 'Test tag',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tag_created',
            'resource_type' => 'tag',
        ]);
    }

    public function test_tag_deleted_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->deleteJson("/api/tags/{$tag->id}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tag_deleted',
            'resource_type' => 'tag',
            'resource_id' => $tag->id,
        ]);
    }

    // ── Connection audit logging ─────────────────────────────────

    public function test_connection_created_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->postJson('/api/connections', [
            'user_id' => $target->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'connection_created',
            'resource_type' => 'connection',
        ]);
    }

    public function test_connection_accepted_is_audit_logged(): void
    {
        $requester = User::factory()->create();
        $addressee = User::factory()->create();
        $connection = Connection::create([
            'requester_id' => $requester->id,
            'addressee_id' => $addressee->id,
            'status' => 'pending',
        ]);
        $token = $addressee->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        // Clear logs from connection creation
        AuditLog::truncate();

        $this->withToken($token)->postJson("/api/connections/{$connection->id}/accept");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'connection_updated',
            'resource_type' => 'connection',
        ]);
    }

    public function test_connection_deleted_is_audit_logged(): void
    {
        $requester = User::factory()->create();
        $addressee = User::factory()->create();
        $connection = Connection::create([
            'requester_id' => $requester->id,
            'addressee_id' => $addressee->id,
            'status' => 'accepted',
        ]);
        $token = $requester->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->deleteJson("/api/connections/{$connection->id}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'connection_deleted',
            'resource_type' => 'connection',
            'resource_id' => $connection->id,
        ]);
    }

    // ── Auth event audit logging ─────────────────────────────────

    public function test_login_is_audit_logged(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_login',
            'resource_type' => 'user',
            'resource_id' => $user->id,
            'status' => 'success',
        ]);
    }

    public function test_failed_login_is_audit_logged(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_failed',
            'status' => 'failed',
        ]);
    }

    public function test_registration_is_audit_logged(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Audit Test User',
            'email' => 'audit-test@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_registered',
            'resource_type' => 'user',
            'status' => 'success',
        ]);
    }

    public function test_logout_is_audit_logged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->postJson('/api/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_logout',
            'resource_type' => 'user',
            'resource_id' => $user->id,
            'status' => 'success',
        ]);
    }

    // ── Admin action audit logging ───────────────────────────────

    public function test_admin_user_deletion_is_audit_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_deleted',
            'resource_type' => 'user',
            'resource_id' => $target->id,
        ]);
    }

    public function test_admin_feature_flag_update_is_audit_logged(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/admin/settings/features', [
            'dates' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appsetting_updated',
            'resource_type' => 'appsetting',
        ]);
    }

    // ── Metadata captures before/after for updates ───────────────

    public function test_update_audit_log_captures_before_and_after(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'Before', 'color' => '#000000']);
        $token = $user->createToken('test', [TokenAbility::Api->value])->plainTextToken;

        $this->withToken($token)->putJson("/api/tags/{$tag->id}", [
            'name' => 'After',
        ]);

        $log = AuditLog::where('action', 'tag_updated')
            ->where('resource_id', $tag->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('before', $log->metadata);
        $this->assertArrayHasKey('after', $log->metadata);
        $this->assertEquals('Before', $log->metadata['before']['name']);
        $this->assertEquals('After', $log->metadata['after']['name']);
    }

    // ── Timestamp-only changes are not logged ────────────────────

    public function test_timestamp_only_changes_are_not_audit_logged(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->for($user)->create();

        $initialCount = AuditLog::count();

        // Touch only updates timestamps
        $item->touch();

        $this->assertEquals($initialCount, AuditLog::count());
    }

    // ── Sensitive field redaction ────────────────────────────────

    public function test_user_created_audit_log_does_not_contain_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Redact Test',
            'email' => 'redact-test@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]);

        $log = AuditLog::where('action', 'user_created')->first();
        $this->assertNotNull($log);

        // The 'after' metadata should never contain password or remember_token
        $after = $log->metadata['after'] ?? [];
        $this->assertArrayNotHasKey('password', $after);
        $this->assertArrayNotHasKey('remember_token', $after);
    }

    public function test_user_deleted_audit_log_does_not_contain_password(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}");

        $log = AuditLog::where('action', 'user_deleted')
            ->where('resource_id', $target->id)
            ->first();

        $this->assertNotNull($log);

        $before = $log->metadata['before'] ?? [];
        $this->assertArrayNotHasKey('password', $before);
        $this->assertArrayNotHasKey('remember_token', $before);
    }
}
