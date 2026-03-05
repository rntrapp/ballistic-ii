<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Project;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List Projects')]
#[IsReadOnly]
#[IsIdempotent]
final class ListProjects extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            List the authenticated user's projects. Archived projects are excluded
            by default. Use this to discover valid project_id values before calling
            create_task or update_task — foreign project IDs will be rejected.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_archived')
            ->description('Also return archived projects (default false).')
            ->boolean('with_task_counts')
            ->description('Include an `active_task_count` field on each project (default false).');
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'include_archived' => ['nullable', 'boolean'],
            'with_task_counts' => ['nullable', 'boolean'],
        ]);

        $query = $this->guard()->scopeToOwner(Project::query())
            ->orderBy('name');

        if (! ($validated['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }

        if ($validated['with_task_counts'] ?? false) {
            $query->withCount([
                'items as active_task_count' => static fn ($q) => $q->whereNotIn('status', ['done', 'wontdo']),
            ]);
        }

        $projects = $query->limit($this->limit())->get();

        return $this->success([
            'count' => $projects->count(),
            'projects' => $projects->map(static fn (Project $p): array => array_filter([
                'id' => (string) $p->id,
                'name' => $p->name,
                'color' => $p->color,
                'archived' => $p->archived_at !== null,
                'active_task_count' => $p->getAttribute('active_task_count'),
            ], static fn ($v) => $v !== null))->all(),
        ]);
    }
}
