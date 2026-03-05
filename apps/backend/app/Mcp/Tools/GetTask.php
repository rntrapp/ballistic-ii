<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Get Task')]
#[IsReadOnly]
#[IsIdempotent]
final class GetTask extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return 'Fetch full details for a single task by ID. Denied if the task does not belong to you.';
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('UUID of the task to fetch.')
            ->required();
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'id' => ['required', 'string', 'uuid'],
        ]);

        /** @var Item $item */
        $item = $this->guard()->findOrDeny(Item::class, $validated['id'], 'view');
        $item->load(['project:id,name,color', 'tags:id,name,color', 'assignee:id,name,email']);

        return $this->success([
            'id' => (string) $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'project' => $item->project !== null
                ? ['id' => (string) $item->project->id, 'name' => $item->project->name]
                : null,
            'scheduled_date' => $item->scheduled_date?->toDateString(),
            'due_date' => $item->due_date?->toDateString(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'assignee_notes' => $item->assignee_notes,
            'recurrence_rule' => $item->recurrence_rule,
            'tags' => $item->tags->map(static fn ($t): array => [
                'id' => (string) $t->id,
                'name' => $t->name,
            ])->all(),
            'assignee' => $item->assignee !== null
                ? ['id' => (string) $item->assignee->id, 'name' => $item->assignee->name]
                : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ]);
    }
}
