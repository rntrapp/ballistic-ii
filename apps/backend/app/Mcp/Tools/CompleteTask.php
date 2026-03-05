<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Complete Task')]
#[IsIdempotent]
final class CompleteTask extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            Convenience shortcut for marking a task as done (or re-opening it).
            Equivalent to calling update_task with status=done, but faster for
            agents performing bulk triage. Idempotent: completing an already-done
            task is a no-op.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('UUID of the task to complete (or re-open).')
            ->required()
            ->boolean('reopen')
            ->description('If true, move a completed task back to "todo" instead. Defaults to false.');
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'id' => ['required', 'uuid'],
            'reopen' => ['nullable', 'boolean'],
        ]);

        /** @var Item $item */
        $item = $this->guard()->findOrDeny(Item::class, $validated['id'], 'update');

        $reopen = (bool) ($validated['reopen'] ?? false);

        if ($reopen) {
            if ($item->status === 'done' || $item->status === 'wontdo') {
                $item->update(['status' => 'todo', 'completed_at' => null]);
            }
        } else {
            if ($item->status !== 'done') {
                $item->update(['status' => 'done', 'completed_at' => now()]);
            }
        }

        return $this->success([
            'id' => (string) $item->id,
            'title' => $item->title,
            'status' => $item->status,
            'completed_at' => $item->completed_at?->toIso8601String(),
        ], $reopen ? 'Task re-opened.' : 'Task completed.');
    }
}
