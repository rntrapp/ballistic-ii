<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_can_create_project(): void
    {
        $user = User::factory()->create();

        $projectData = [
            'user_id' => $user->id,
            'name' => 'Test Project',
            'color' => '#FF5733',
        ];

        $project = Project::create($projectData);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'user_id' => $user->id,
            'name' => 'Test Project',
            'color' => '#FF5733',
        ]);
    }

    public function test_project_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $project->user);
        $this->assertEquals($user->id, $project->user->id);
    }

    public function test_can_archive_project(): void
    {
        $project = Project::factory()->create();

        $this->assertNull($project->archived_at);

        $project->update(['archived_at' => now()]);

        $this->assertNotNull($project->fresh()->archived_at);
    }

    public function test_can_restore_archived_project(): void
    {
        $project = Project::factory()->create(['archived_at' => now()]);

        $this->assertNotNull($project->archived_at);

        $project->update(['archived_at' => null]);

        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_project_uses_uuid_as_primary_key(): void
    {
        $project = Project::factory()->create();

        $this->assertNotNull($project->id);
        $this->assertEquals(36, strlen((string) $project->id)); // UUID length
    }

    public function test_project_factory_creates_valid_data(): void
    {
        $project = Project::factory()->create();

        $this->assertNotEmpty($project->name);
        $this->assertInstanceOf(User::class, $project->user);
        $this->assertNotNull($project->id);
    }

    /**
     * Resource-shape guard: the Project model uses SoftDeletes and the
     * frontend Project type declares deleted_at: string | null, so the
     * resource must emit the key (null for live rows).
     */
    public function test_project_resource_includes_deleted_at_key(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['deleted_at']])
            ->assertJsonPath('data.deleted_at', null);
    }
}
