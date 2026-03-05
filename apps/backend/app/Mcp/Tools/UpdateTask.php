<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Update Task')]
#[IsIdempotent]
final class UpdateTask extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            Patch one or more fields on a task you own. Only the supplied fields
            change; others are preserved. Attempting to update another user's task
            yields a denial indistinguishable from "not found".
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema
            ->string('id')
            ->description('UUID of the task to update.')
            ->required();

        // Dynamically append all writable columns — nothing required for patches.
        $this->schemas()->applyTo($schema, 'item', descriptions: [
            'title' => 'New title.',
            'description' => 'New description / notes.',
            'status' => 'New status: todo | doing | done | wontdo.',
            'project_id' => 'Move to this project (UUID, must belong to you) or null for inbox.',
        ]);

        return $schema->raw('tag_ids', [
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'uuid'],
            'description' => 'Replace the task\'s tag set (must belong to you).',
        ]);
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $user = $this->guard()->user();

        $validated = $this->validate($arguments, [
            'id' => ['required', 'uuid'],
            'title' => ['sometimes', 'string', 'max:65535'],
            'description' => ['nullable', 'string', 'max:65535'],
            'status' => ['sometimes', Rule::in(['todo', 'doing', 'done', 'wontdo'])],
            'project_id' => ['nullable', 'uuid'],
            'scheduled_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:scheduled_date'],
            'recurrence_rule' => ['nullable', 'string', 'max:255'],
            'recurrence_strategy' => ['nullable', Rule::in(['expires', 'carry_over'])],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'uuid',
                Rule::exists('tags', 'id')->where('user_id', $user->id),
            ],
        ], [
            'tag_ids.*.exists' => 'One or more selected tags do not exist or do not belong to you.',
        ]);

        /** @var Item $item */
        $item = $this->guard()->findOrDeny(Item::class, $validated['id'], 'update');

        $tagIds = array_key_exists('tag_ids', $arguments) ? ($validated['tag_ids'] ?? []) : null;

        // Sanitise raw arguments so dynamically-added columns survive, then
        // verify every tenant-scoped FK reference in the payload belongs to us.
        $clean = $this->guard()->sanitise($arguments, 'item');
        unset($clean['id'], $clean['tag_ids']);
        $this->guard()->assertOwnsReferences($clean, 'item');

        // Mirror controller behaviour: auto-manage completed_at on status transitions.
        if (isset($clean['status'])) {
            if ($clean['status'] === 'done' && $item->status !== 'done') {
                $clean['completed_at'] = now();
            } elseif ($clean['status'] !== 'done' && $item->status === 'done') {
                $clean['completed_at'] = null;
            }
        }

        DB::transaction(function () use ($item, $clean, $tagIds): void {
            if (! empty($clean)) {
                $item->update($clean);
            }
            if ($tagIds !== null) {
                $item->tags()->sync($tagIds);
            }
        });

        return $this->success([
            'id' => (string) $item->id,
            'title' => $item->title,
            'status' => $item->status,
            'project_id' => $item->project_id,
        ], 'Task updated.');
    }
}
