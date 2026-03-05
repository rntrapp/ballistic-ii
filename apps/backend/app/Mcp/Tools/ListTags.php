<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Tag;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List Tags')]
#[IsReadOnly]
#[IsIdempotent]
final class ListTags extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            List the authenticated user's tags. Use this to discover valid tag
            UUIDs for the tag_ids argument on create_task / update_task. Tags
            belonging to other users are invisible and will be rejected.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('search')
            ->description('Optional case-insensitive substring filter on tag name.');
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $validated = $this->validate($arguments, [
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = $this->guard()->scopeToOwner(Tag::query())
            ->orderBy('name');

        if (isset($validated['search']) && $validated['search'] !== '') {
            $query->where('name', 'like', '%'.$validated['search'].'%');
        }

        $tags = $query->limit($this->limit())->get();

        return $this->success([
            'count' => $tags->count(),
            'tags' => $tags->map(static fn (Tag $t): array => [
                'id' => (string) $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])->all(),
        ]);
    }
}
