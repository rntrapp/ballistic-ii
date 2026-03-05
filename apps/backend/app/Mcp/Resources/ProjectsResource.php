<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\ContextGuard;
use App\Models\Project;
use Laravel\Mcp\Server\Resource;

/**
 * Read-only snapshot of the authenticated user's active projects.
 *
 * Intended as cheap reference material an agent can fetch once at the
 * start of a session so it knows which project_id values are valid
 * without round-tripping to list_projects for every task creation.
 */
final class ProjectsResource extends Resource
{
    protected string $description = 'JSON array of your active (non-archived) projects with their UUIDs, names, and colours. Refresh after creating a project.';

    #[\Override]
    public function uri(): string
    {
        return 'ballistic://projects';
    }

    #[\Override]
    public function mimeType(): string
    {
        return 'application/json';
    }

    #[\Override]
    public function read(): string
    {
        $guard = app(ContextGuard::class);

        $projects = $guard->scopeToOwner(Project::query())
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(static fn (Project $p): array => [
                'id' => (string) $p->id,
                'name' => $p->name,
                'color' => $p->color,
            ])
            ->all();

        return json_encode($projects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
