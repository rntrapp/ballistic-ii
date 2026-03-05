<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\SchemaGenerator;
use Laravel\Mcp\Server\Resource;

/**
 * Live JSON Schema for all MCP-exposed models, introspected from the DB.
 * Auto-refreshes after migrations (cache key fingerprinted by batch number).
 */
final class SchemaResource extends Resource
{
    protected string $description = 'Dynamically-generated JSON Schema for writable columns on items, projects, and tags.';

    #[\Override]
    public function uri(): string
    {
        return 'ballistic://schema';
    }

    #[\Override]
    public function mimeType(): string
    {
        return 'application/schema+json';
    }

    #[\Override]
    public function read(): string
    {
        $generator = app(SchemaGenerator::class);

        $schemas = [];
        foreach (array_keys(config('mcp.models', [])) as $key) {
            $schemas[$key] = $generator->jsonSchemaFor($key);
        }

        return json_encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'definitions' => $schemas,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
