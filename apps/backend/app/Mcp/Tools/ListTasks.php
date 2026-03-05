<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List Tasks')]
#[IsReadOnly]
#[IsIdempotent]
final class ListTasks extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            List the authenticated user's tasks (todo items). Returns tasks you own
            that are NOT assigned to someone else. Completed and cancelled tasks are
            excluded by default. Use filters to narrow by status, project, or scope.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('status', [
                'type' => ['string', 'null'],
                'enum' => ['todo', 'doing', 'done', 'wontdo'],
                'description' => 'Filter by status. Omit to show active (todo/doing) tasks only.',
            ])
            ->raw('project_id', [
                'type' => ['string', 'null'],
                'description' => 'Filter by project UUID. Omit for all projects (including inbox).',
            ])
            ->raw('scope', [
                'type' => 'string',
                'enum' => ['active', 'planned', 'all'],
                'default' => 'active',
                'description' => '"active" (default) hides future-scheduled tasks; "planned" shows only future; "all" shows everything.',
            ])
            ->boolean('include_completed')
            ->description('Include done/wontdo tasks in results (default false).')
            ->integer('limit')
            ->description("Max results (capped at {$this->limit()}).");
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'status' => ['nullable', Rule::in(['todo', 'doing', 'done', 'wontdo'])],
            'project_id' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in(['active', 'planned', 'all'])],
            'include_completed' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->guard()->scopeToOwner(Item::query())
            ->whereNull('assignee_id')
            ->with(['project:id,name,color', 'tags:id,name,color'])
            ->orderBy('position');

        if (! ($validated['include_completed'] ?? false)) {
            $query->whereNotIn('status', ['done', 'wontdo']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('project_id', $validated)) {
            $query->where('project_id', $validated['project_id']);
        }

        match ($validated['scope'] ?? 'active') {
            'planned' => $query->planned(),
            'all' => null,
            default => $query->active(),
        };

        $limit = min((int) ($validated['limit'] ?? $this->limit()), $this->limit());
        $items = $query->limit($limit)->get();

        return $this->success([
            'count' => $items->count(),
            'tasks' => $items->map(fn (Item $item): array => $this->serialise($item))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(Item $item): array
    {
        return [
            'id' => (string) $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'project_id' => $item->project_id,
            'project_name' => $item->project?->name,
            'scheduled_date' => $item->scheduled_date?->toDateString(),
            'due_date' => $item->due_date?->toDateString(),
            'tags' => $item->tags->pluck('name')->all(),
        ];
    }
}
