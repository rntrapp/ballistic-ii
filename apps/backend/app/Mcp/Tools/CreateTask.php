<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Create Task')]
final class CreateTask extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            Create a new task for the authenticated user. Supports optional project
            selection, description/note generation, scheduling, due dates, and tag
            attachment. The new task always belongs to the calling user; attempts to
            inject foreign ownership fields are silently stripped. The input schema
            below is dynamically derived from the live database — any new column on
            the `items` table automatically becomes a valid argument.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        // Dynamically introspect writable columns from the live DB schema.
        // New migrations surface automatically without a code deploy.
        $this->schemas()->applyTo($schema, 'item', required: ['title'], descriptions: [
            'title' => 'Short, action-oriented task title (required).',
            'description' => 'Longer free-form notes describing the task.',
            'status' => 'One of: todo, doing, done, wontdo. Defaults to "todo".',
            'project_id' => 'Optional project UUID. Must belong to you. Omit/null for inbox.',
            'scheduled_date' => 'ISO date when the task becomes active (YYYY-MM-DD).',
            'due_date' => 'ISO deadline date (YYYY-MM-DD). Must be >= scheduled_date.',
        ]);

        return $schema->raw('tag_ids', [
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'uuid'],
            'description' => 'Tag UUIDs to attach (must belong to you).',
        ]);
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $user = $this->guard()->user();

        $validated = $this->validate($arguments, [
            'title' => ['required', 'string', 'max:65535'],
            'description' => ['nullable', 'string', 'max:65535'],
            'status' => ['nullable', Rule::in(['todo', 'doing', 'done', 'wontdo'])],
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

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        // Strip deny-listed keys, then verify every remaining FK references a
        // row you own — this covers project_id today AND any FK column added
        // by a future migration without a code change.
        $clean = $this->guard()->sanitise($arguments, 'item');
        $this->guard()->assertOwnsReferences($clean, 'item');

        $item = DB::transaction(function () use ($clean, $tagIds, $user): Item {
            $item = Item::create([
                ...$clean,
                'status' => $clean['status'] ?? 'todo',
                'user_id' => $user->id,
                'position' => 0,
                'completed_at' => ($clean['status'] ?? null) === 'done' ? now() : null,
            ]);

            if (! empty($tagIds)) {
                $item->tags()->sync($tagIds);
            }

            return $item;
        });

        $item->load(['project:id,name', 'tags:id,name']);

        return $this->success([
            'id' => (string) $item->id,
            'title' => $item->title,
            'status' => $item->status,
            'project' => $item->project !== null
                ? ['id' => (string) $item->project->id, 'name' => $item->project->name]
                : null,
            'tags' => $item->tags->pluck('name')->all(),
        ], 'Task created successfully.');
    }
}
