<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SchemaResource;
use App\Mcp\Resources\TagsResource;
use App\Mcp\Tools\CompleteTask;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\DeleteTask;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListTags;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\UpdateTask;
use Laravel\Mcp\Server;

/**
 * The single Ballistic MCP surface — exposed over HTTP at /mcp/ballistic
 * (Sanctum-authenticated) and over STDIO via `artisan mcp:start ballistic`.
 *
 * All tool and resource calls route through App\Mcp\Support\ContextGuard
 * to guarantee hard tenant isolation: reading or mutating another user's
 * rows is indistinguishable from those rows not existing.
 */
final class BallisticServer extends Server
{
    public string $serverName = 'Ballistic MCP';

    public string $serverVersion = '1.0.0';

    public string $instructions = <<<'INSTRUCTIONS'
        You are connected to Ballistic, a personal task/project manager. Every
        row in this system is owned by a single user; you can ONLY see and
        modify rows belonging to the authenticated bearer-token user.

        Typical workflow:
          1. Call list_projects and list_tags (or read ballistic://projects
             and ballistic://tags) to discover valid UUIDs for filtering.
          2. Call list_tasks to see the current backlog (active, unassigned
             tasks by default — use scope/include_completed to widen).
          3. Use create_task to add new work, supplying a project_id from
             step 1 and tag_ids as needed.
          4. Use update_task for partial edits or complete_task for quick
             status flips. Both are idempotent.

        All IDs are UUIDv4 strings. Attempting to reference another user's
        project, tag, or task will yield a "not found or not yours" denial —
        do NOT retry with guessed IDs.
        INSTRUCTIONS;

    public array $tools = [
        ListTasks::class,
        GetTask::class,
        CreateTask::class,
        UpdateTask::class,
        CompleteTask::class,
        DeleteTask::class,
        ListProjects::class,
        CreateProject::class,
        ListTags::class,
    ];

    public array $resources = [
        ProjectsResource::class,
        TagsResource::class,
        SchemaResource::class,
    ];

    public array $prompts = [];

    #[\Override]
    public function boot(): void
    {
        $this->serverName = (string) config('mcp.server_name', $this->serverName);
        $this->serverVersion = (string) config('mcp.server_version', $this->serverVersion);
    }
}
