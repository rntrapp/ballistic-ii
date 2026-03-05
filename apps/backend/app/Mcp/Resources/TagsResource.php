<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\ContextGuard;
use App\Models\Tag;
use Laravel\Mcp\Server\Resource;

/**
 * Read-only snapshot of the authenticated user's tags.
 *
 * Lets an agent resolve tag names → UUIDs without a separate tool
 * call, handy when the user asks to "tag this as urgent".
 */
final class TagsResource extends Resource
{
    protected string $description = 'JSON array of your tags with their UUIDs, names, and colours. Use these UUIDs in the tag_ids argument when creating or updating tasks.';

    #[\Override]
    public function uri(): string
    {
        return 'ballistic://tags';
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

        $tags = $guard->scopeToOwner(Tag::query())
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(static fn (Tag $t): array => [
                'id' => (string) $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])
            ->all();

        return json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
