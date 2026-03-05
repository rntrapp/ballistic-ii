<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Delete Task')]
#[IsDestructive]
#[IsIdempotent]
final class DeleteTask extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            Permanently remove a task. Soft-deleted in the DB so it can be
            recovered administratively, but the task is immediately hidden from
            all list endpoints and other tools. Only the task owner may delete.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('UUID of the task to delete.')
            ->required();
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'id' => ['required', 'uuid'],
        ]);

        /** @var Item $item */
        $item = $this->guard()->findOrDeny(Item::class, $validated['id'], 'delete');

        $item->delete();

        return $this->success([
            'id' => $validated['id'],
            'deleted' => true,
        ], 'Task deleted.');
    }
}
