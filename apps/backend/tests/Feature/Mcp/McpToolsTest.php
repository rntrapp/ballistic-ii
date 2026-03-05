<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\Concerns\InteractsWithMcp;
use Tests\TestCase;

/**
 * Happy-path exercises for every MCP tool. Verifies the end-to-end
 * agent interaction success criteria: read task list + write new task
 * including project selection and tag attachment.
 */
final class McpToolsTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    // -------------------------------------------------------------------------
    //  list-tasks
    // -------------------------------------------------------------------------

    public function test_list_tasks_returns_only_owned_active_unassigned_tasks(): void
    {
        $owner = $this->asUser();
        Item::factory()->count(2)->inbox()->todo()->create(['user_id' => $owner->id]);
        Item::factory()->inbox()->done()->create(['user_id' => $owner->id]); // completed → excluded
        Item::factory()->inbox()->todo()->create(); // foreign user → excluded

        $data = $this->callToolOk('list-tasks');

        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['tasks']);
        foreach ($data['tasks'] as $task) {
            $this->assertContains($task['status'], ['todo', 'doing']);
        }
    }

    public function test_list_tasks_include_completed_flag_shows_done_tasks(): void
    {
        $owner = $this->asUser();
        Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);
        Item::factory()->inbox()->done()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('list-tasks', ['include_completed' => true]);

        $this->assertSame(2, $data['count']);
    }

    public function test_list_tasks_filters_by_project_id(): void
    {
        $owner = $this->asUser();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        Item::factory()->todo()->create(['user_id' => $owner->id, 'project_id' => $project->id]);
        Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('list-tasks', ['project_id' => (string) $project->id]);

        $this->assertSame(1, $data['count']);
        $this->assertSame((string) $project->id, $data['tasks'][0]['project_id']);
    }

    public function test_list_tasks_scope_all_includes_future_scheduled(): void
    {
        $owner = $this->asUser();
        Item::factory()->inbox()->todo()->futureScheduled()->create(['user_id' => $owner->id]);
        Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);

        $active = $this->callToolOk('list-tasks'); // default scope=active
        $all = $this->callToolOk('list-tasks', ['scope' => 'all']);

        $this->assertSame(1, $active['count']);
        $this->assertSame(2, $all['count']);
    }

    public function test_list_tasks_respects_limit_cap(): void
    {
        $owner = $this->asUser();
        Item::factory()->count(5)->inbox()->todo()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('list-tasks', ['limit' => 2]);

        $this->assertSame(2, $data['count']);
    }

    // -------------------------------------------------------------------------
    //  get-task
    // -------------------------------------------------------------------------

    public function test_get_task_returns_full_details_for_owned_task(): void
    {
        $owner = $this->asUser();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $tag = Tag::factory()->create(['user_id' => $owner->id]);
        $item = Item::factory()->todo()->create([
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'title' => 'Detail check',
            'description' => 'Lorem',
        ]);
        $item->tags()->attach((string) $tag->id);

        $data = $this->callToolOk('get-task', ['id' => (string) $item->id]);

        $this->assertSame((string) $item->id, $data['id']);
        $this->assertSame('Detail check', $data['title']);
        $this->assertSame('Lorem', $data['description']);
        $this->assertSame($project->name, $data['project']['name']);
        $this->assertCount(1, $data['tags']);
    }

    // -------------------------------------------------------------------------
    //  create-task — core success criterion: agent writes a new assignment
    // -------------------------------------------------------------------------

    public function test_create_task_persists_minimal_task(): void
    {
        $owner = $this->asUser();

        $data = $this->callToolOk('create-task', ['title' => 'Buy milk']);

        $this->assertNotEmpty($data['id']);
        $this->assertSame('Buy milk', $data['title']);
        $this->assertSame('todo', $data['status']);
        $this->assertDatabaseHas('items', [
            'id' => $data['id'],
            'title' => 'Buy milk',
            'user_id' => $owner->id,
        ]);
    }

    public function test_create_task_with_project_and_tags_and_notes(): void
    {
        $owner = $this->asUser();
        $project = Project::factory()->create(['user_id' => $owner->id, 'name' => 'Home']);
        $tags = Tag::factory()->count(2)->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('create-task', [
            'title' => 'Plan holiday',
            'description' => 'Research flights and accommodation',
            'project_id' => (string) $project->id,
            'tag_ids' => $tags->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'status' => 'doing',
            'scheduled_date' => now()->toDateString(),
        ]);

        $this->assertSame('Home', $data['project']['name']);
        $this->assertSame('doing', $data['status']);
        $this->assertCount(2, $data['tags']);

        $item = Item::query()->find($data['id']);
        $this->assertNotNull($item);
        $this->assertSame((string) $project->id, (string) $item->project_id);
        $this->assertSame('Research flights and accommodation', $item->description);
        $this->assertCount(2, $item->tags);
    }

    public function test_create_task_defaults_to_todo_when_status_omitted(): void
    {
        $this->asUser();

        $data = $this->callToolOk('create-task', ['title' => 'No status']);
        $this->assertSame('todo', $data['status']);
    }

    public function test_create_task_as_done_sets_completed_at(): void
    {
        $owner = $this->asUser();

        $data = $this->callToolOk('create-task', [
            'title' => 'Already done',
            'status' => 'done',
        ]);

        $item = Item::query()->find($data['id']);
        $this->assertNotNull($item->completed_at);
    }

    // -------------------------------------------------------------------------
    //  update-task
    // -------------------------------------------------------------------------

    public function test_update_task_patches_only_supplied_fields(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create([
            'user_id' => $owner->id,
            'title' => 'Original',
            'description' => 'Keep me',
        ]);

        $data = $this->callToolOk('update-task', [
            'id' => (string) $item->id,
            'title' => 'Renamed',
        ]);

        $this->assertSame('Renamed', $data['title']);
        $item->refresh();
        $this->assertSame('Renamed', $item->title);
        $this->assertSame('Keep me', $item->description); // preserved
    }

    public function test_update_task_to_done_auto_sets_completed_at(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);
        $this->assertNull($item->completed_at);

        $this->callToolOk('update-task', ['id' => (string) $item->id, 'status' => 'done']);

        $item->refresh();
        $this->assertNotNull($item->completed_at);
    }

    public function test_update_task_from_done_to_todo_clears_completed_at(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->done()->create(['user_id' => $owner->id]);
        $this->assertNotNull($item->completed_at);

        $this->callToolOk('update-task', ['id' => (string) $item->id, 'status' => 'todo']);

        $item->refresh();
        $this->assertNull($item->completed_at);
    }

    public function test_update_task_replaces_tag_set_when_tag_ids_supplied(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);
        $oldTag = Tag::factory()->create(['user_id' => $owner->id]);
        $newTag = Tag::factory()->create(['user_id' => $owner->id]);
        $item->tags()->attach((string) $oldTag->id);

        $this->callToolOk('update-task', [
            'id' => (string) $item->id,
            'tag_ids' => [(string) $newTag->id],
        ]);

        $item->refresh();
        $this->assertCount(1, $item->tags);
        $this->assertSame((string) $newTag->id, (string) $item->tags->first()->id);
    }

    public function test_update_task_moves_project(): void
    {
        $owner = $this->asUser();
        $from = Project::factory()->create(['user_id' => $owner->id]);
        $to = Project::factory()->create(['user_id' => $owner->id]);
        $item = Item::factory()->todo()->create(['user_id' => $owner->id, 'project_id' => $from->id]);

        $this->callToolOk('update-task', [
            'id' => (string) $item->id,
            'project_id' => (string) $to->id,
        ]);

        $item->refresh();
        $this->assertSame((string) $to->id, (string) $item->project_id);
    }

    // -------------------------------------------------------------------------
    //  complete-task
    // -------------------------------------------------------------------------

    public function test_complete_task_marks_todo_as_done(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('complete-task', ['id' => (string) $item->id]);

        $this->assertSame('done', $data['status']);
        $this->assertNotNull($data['completed_at']);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'status' => 'done']);
    }

    public function test_complete_task_is_idempotent(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->done()->create(['user_id' => $owner->id]);
        $originalCompletedAt = $item->completed_at;

        $this->callToolOk('complete-task', ['id' => (string) $item->id]);

        $item->refresh();
        $this->assertEquals($originalCompletedAt->timestamp, $item->completed_at->timestamp);
    }

    public function test_complete_task_reopen_moves_done_back_to_todo(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->done()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('complete-task', [
            'id' => (string) $item->id,
            'reopen' => true,
        ]);

        $this->assertSame('todo', $data['status']);
        $this->assertNull($data['completed_at']);
    }

    // -------------------------------------------------------------------------
    //  delete-task
    // -------------------------------------------------------------------------

    public function test_delete_task_soft_deletes_and_hides_from_lists(): void
    {
        $owner = $this->asUser();
        $item = Item::factory()->inbox()->todo()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('delete-task', ['id' => (string) $item->id]);

        $this->assertTrue($data['deleted']);
        $this->assertSoftDeleted('items', ['id' => $item->id]);

        $list = $this->callToolOk('list-tasks');
        $this->assertSame(0, $list['count']);
    }

    // -------------------------------------------------------------------------
    //  list-projects / create-project
    // -------------------------------------------------------------------------

    public function test_list_projects_excludes_archived_by_default(): void
    {
        $owner = $this->asUser();
        Project::factory()->count(2)->create(['user_id' => $owner->id]);
        Project::factory()->archived()->create(['user_id' => $owner->id]);
        Project::factory()->create(); // foreign

        $data = $this->callToolOk('list-projects');

        $this->assertSame(2, $data['count']);
        foreach ($data['projects'] as $p) {
            $this->assertFalse($p['archived']);
        }
    }

    public function test_list_projects_include_archived_shows_all(): void
    {
        $owner = $this->asUser();
        Project::factory()->create(['user_id' => $owner->id]);
        Project::factory()->archived()->create(['user_id' => $owner->id]);

        $data = $this->callToolOk('list-projects', ['include_archived' => true]);

        $this->assertSame(2, $data['count']);
    }

    public function test_list_projects_with_task_counts(): void
    {
        $owner = $this->asUser();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        Item::factory()->count(3)->todo()->create(['user_id' => $owner->id, 'project_id' => $project->id]);
        Item::factory()->done()->create(['user_id' => $owner->id, 'project_id' => $project->id]);

        $data = $this->callToolOk('list-projects', ['with_task_counts' => true]);

        $this->assertSame(3, $data['projects'][0]['active_task_count']);
    }

    public function test_create_project_persists_and_returns_uuid(): void
    {
        $owner = $this->asUser();

        $data = $this->callToolOk('create-project', [
            'name' => 'Side Hustle',
            'color' => '#FF5733',
        ]);

        $this->assertNotEmpty($data['id']);
        $this->assertSame('Side Hustle', $data['name']);
        $this->assertDatabaseHas('projects', [
            'id' => $data['id'],
            'name' => 'Side Hustle',
            'user_id' => $owner->id,
        ]);
    }

    // -------------------------------------------------------------------------
    //  list-tags
    // -------------------------------------------------------------------------

    public function test_list_tags_returns_only_owned_tags(): void
    {
        $owner = $this->asUser();
        Tag::factory()->count(3)->create(['user_id' => $owner->id]);
        Tag::factory()->count(5)->create(); // foreign

        $data = $this->callToolOk('list-tags');

        $this->assertSame(3, $data['count']);
    }

    public function test_list_tags_search_filters_by_name(): void
    {
        $owner = $this->asUser();
        Tag::factory()->create(['user_id' => $owner->id, 'name' => 'urgent']);
        Tag::factory()->create(['user_id' => $owner->id, 'name' => 'home']);
        Tag::factory()->create(['user_id' => $owner->id, 'name' => 'work']);

        $data = $this->callToolOk('list-tags', ['search' => 'urg']);

        $this->assertSame(1, $data['count']);
        $this->assertSame('urgent', $data['tags'][0]['name']);
    }

    // -------------------------------------------------------------------------
    //  End-to-end agent interaction: read list then write new assignment
    // -------------------------------------------------------------------------

    public function test_agent_workflow_read_tasks_then_write_new_assignment(): void
    {
        $owner = $this->asUser();
        $project = Project::factory()->create(['user_id' => $owner->id, 'name' => 'Q2 Goals']);
        $tag = Tag::factory()->create(['user_id' => $owner->id, 'name' => 'priority']);
        Item::factory()->count(2)->inbox()->todo()->create(['user_id' => $owner->id]);

        // Agent step 1: discover projects + tags
        $projects = $this->callToolOk('list-projects');
        $tags = $this->callToolOk('list-tags');
        $this->assertSame(1, $projects['count']);
        $this->assertSame(1, $tags['count']);

        // Agent step 2: read current task list
        $before = $this->callToolOk('list-tasks');
        $this->assertSame(2, $before['count']);

        // Agent step 3: write a new assignment with project selection + notes
        $created = $this->callToolOk('create-task', [
            'title' => 'Draft Q2 roadmap',
            'description' => 'Compile goals with stakeholder input.',
            'project_id' => $projects['projects'][0]['id'],
            'tag_ids' => [$tags['tags'][0]['id']],
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $this->assertSame('Q2 Goals', $created['project']['name']);
        $this->assertContains('priority', $created['tags']);

        // Agent step 4: verify the write landed
        $after = $this->callToolOk('list-tasks');
        $this->assertSame(3, $after['count']);

        $fetched = $this->callToolOk('get-task', ['id' => $created['id']]);
        $this->assertSame('Draft Q2 roadmap', $fetched['title']);
        $this->assertSame('Compile goals with stakeholder input.', $fetched['description']);
    }
}
