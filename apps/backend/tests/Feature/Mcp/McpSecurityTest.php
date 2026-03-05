<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Mcp\Concerns\InteractsWithMcp;
use Tests\TestCase;

/**
 * Hard guardrail tests: tenant isolation, anti-enumeration, payload
 * injection stripping, and input validation failures. Every sad path
 * an external agent could trigger.
 */
final class McpSecurityTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    // -------------------------------------------------------------------------
    //  Authentication
    // -------------------------------------------------------------------------

    public function test_all_tool_calls_require_authentication(): void
    {
        // No Sanctum::actingAs → middleware should block at transport level.
        $this->postJson('/mcp/ballistic', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list-tasks', 'arguments' => []],
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    //  Cross-tenant read denial
    // -------------------------------------------------------------------------

    public function test_list_tasks_never_leaks_foreign_user_tasks(): void
    {
        $owner = $this->asUser();
        $victim = User::factory()->create();
        Item::factory()->count(5)->inbox()->todo()->create(['user_id' => $victim->id]);

        $data = $this->callToolOk('list-tasks');

        $this->assertSame(0, $data['count']);
    }

    public function test_get_task_denies_foreign_task_with_opaque_message(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->create(['user_id' => $victim->id]);

        $error = $this->callToolError('get-task', ['id' => (string) $foreign->id]);

        $this->assertStringContainsString('not authorised', $error);
        $this->assertStringNotContainsString((string) $victim->id, $error);
    }

    public function test_get_task_missing_id_yields_identical_error_to_foreign(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->create(['user_id' => $victim->id]);

        $missing = $this->callToolError('get-task', ['id' => (string) Str::uuid()]);
        $foreignErr = $this->callToolError('get-task', ['id' => (string) $foreign->id]);

        $this->assertSame($missing, $foreignErr, 'Enumeration leak: foreign vs missing errors differ.');
    }

    // -------------------------------------------------------------------------
    //  Cross-tenant write denial
    // -------------------------------------------------------------------------

    public function test_update_task_denies_foreign_task(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->todo()->create([
            'user_id' => $victim->id,
            'title' => 'Victim task',
        ]);

        $this->callToolError('update-task', [
            'id' => (string) $foreign->id,
            'title' => 'Hijacked',
        ]);

        $foreign->refresh();
        $this->assertSame('Victim task', $foreign->title);
    }

    public function test_complete_task_denies_foreign_task(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->todo()->create(['user_id' => $victim->id]);

        $this->callToolError('complete-task', ['id' => (string) $foreign->id]);

        $foreign->refresh();
        $this->assertSame('todo', $foreign->status);
    }

    public function test_delete_task_denies_foreign_task(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->todo()->create(['user_id' => $victim->id]);

        $this->callToolError('delete-task', ['id' => (string) $foreign->id]);

        $this->assertDatabaseHas('items', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_delete_task_owner_only_even_for_assignee(): void
    {
        $owner = User::factory()->create();
        $assignee = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee->id,
        ]);

        $this->callToolError('delete-task', ['id' => (string) $item->id]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'deleted_at' => null]);
    }

    // -------------------------------------------------------------------------
    //  Reference integrity: cannot attach foreign projects/tags
    // -------------------------------------------------------------------------

    public function test_create_task_rejects_foreign_project_id(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreign = Project::factory()->create(['user_id' => $victim->id]);

        $error = $this->callToolError('create-task', [
            'title' => 'Escalate',
            'project_id' => (string) $foreign->id,
        ]);

        $this->assertStringContainsString('does not belong to you', $error);
        $this->assertDatabaseMissing('items', ['title' => 'Escalate']);
    }

    public function test_create_task_rejects_foreign_tag_ids(): void
    {
        $this->asUser();
        $victim = User::factory()->create();
        $foreignTag = Tag::factory()->create(['user_id' => $victim->id]);

        $error = $this->callToolError('create-task', [
            'title' => 'Steal tag',
            'tag_ids' => [(string) $foreignTag->id],
        ]);

        $this->assertStringContainsString('belong to you', $error);
        $this->assertDatabaseMissing('items', ['title' => 'Steal tag']);
    }

    public function test_update_task_rejects_moving_to_foreign_project(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);
        $victim = User::factory()->create();
        $foreign = Project::factory()->create(['user_id' => $victim->id]);

        $this->callToolError('update-task', [
            'id' => (string) $item->id,
            'project_id' => (string) $foreign->id,
        ]);

        $item->refresh();
        $this->assertNull($item->project_id);
    }

    public function test_create_task_rejects_foreign_reference_on_dynamically_added_fk(): void
    {
        // The dynamic-schema counterpart: a migration adds a brand-new
        // tenant-scoped FK column. The ContextGuard must detect and enforce
        // ownership on it WITHOUT any per-column rule having been written.
        $this->asUser();
        $victim = User::factory()->create();

        Schema::create('milestones', static function ($table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::table('items', static function ($table): void {
            $table->foreignUuid('milestone_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::table('migrations')->insert([
            'migration' => '9999_01_01_000005_add_milestones_e2e',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        $foreignMilestone = (string) Str::uuid();
        DB::table('milestones')->insert([
            'id' => $foreignMilestone,
            'user_id' => $victim->id,
            'name' => 'Victim milestone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $error = $this->callToolError('create-task', [
            'title' => 'Cross-tenant FK attack',
            'milestone_id' => $foreignMilestone,
        ]);

        $this->assertStringContainsString('does not belong to you', $error);
        $this->assertDatabaseMissing('items', ['title' => 'Cross-tenant FK attack']);
    }

    // -------------------------------------------------------------------------
    //  Payload injection: ownership + system column tampering
    // -------------------------------------------------------------------------

    public function test_create_task_strips_injected_user_id(): void
    {
        $owner = $this->asUser();
        $victim = User::factory()->create();

        $data = $this->callToolOk('create-task', [
            'title' => 'Injection attempt',
            'user_id' => (string) $victim->id, // ← agent tries to donate task to victim
        ]);

        $this->assertDatabaseHas('items', [
            'id' => $data['id'],
            'user_id' => $owner->id, // ← always the authenticated user
        ]);
    }

    public function test_update_task_strips_injected_ownership_and_system_fields(): void
    {
        $owner = $this->asUser();
        $victim = User::factory()->create();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);

        $this->callToolOk('update-task', [
            'id' => (string) $item->id,
            'title' => 'Safe edit',
            'user_id' => (string) $victim->id,
            'assignee_id' => (string) $victim->id,
            'completed_at' => '1999-01-01',
        ]);

        $item->refresh();
        $this->assertSame((string) $owner->id, (string) $item->user_id);
        $this->assertNull($item->assignee_id);
        $this->assertNull($item->completed_at); // status is still todo
        $this->assertSame('Safe edit', $item->title); // legitimate field passed through
    }

    public function test_create_project_strips_injected_user_id(): void
    {
        $owner = $this->asUser();
        $victim = User::factory()->create();

        $data = $this->callToolOk('create-project', [
            'name' => 'Hijack',
            'user_id' => (string) $victim->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $data['id'],
            'user_id' => $owner->id,
        ]);
    }

    // -------------------------------------------------------------------------
    //  Input validation
    // -------------------------------------------------------------------------

    public function test_create_task_fails_without_title(): void
    {
        $this->asUser();

        $error = $this->callToolError('create-task', []);
        $this->assertStringContainsString('title', strtolower($error));
    }

    public function test_create_task_rejects_invalid_status(): void
    {
        $this->asUser();

        $this->callToolError('create-task', [
            'title' => 'x',
            'status' => 'yolo',
        ]);
    }

    public function test_create_task_rejects_malformed_uuid(): void
    {
        $this->asUser();

        $this->callToolError('create-task', [
            'title' => 'x',
            'project_id' => 'not-a-uuid',
        ]);
    }

    public function test_get_task_rejects_malformed_uuid(): void
    {
        $this->asUser();

        $error = $this->callToolError('get-task', ['id' => 'not-a-uuid']);
        $this->assertStringContainsString('uuid', strtolower($error));
    }

    public function test_create_project_rejects_invalid_hex_colour(): void
    {
        $this->asUser();

        $error = $this->callToolError('create-project', [
            'name' => 'Bad colour',
            'color' => '#ZZZZZZ', // correct length but invalid hex
        ]);

        $this->assertStringContainsString('hex', strtolower($error));
    }

    public function test_create_task_rejects_due_date_before_scheduled_date(): void
    {
        $this->asUser();

        $this->callToolError('create-task', [
            'title' => 'x',
            'scheduled_date' => '2026-06-01',
            'due_date' => '2026-01-01',
        ]);
    }
}
