<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

/**
 * Introspects database tables at runtime and emits MCP-compatible JSON
 * Schema definitions. Results are cached against a migration fingerprint
 * so new columns are auto-discovered after `artisan migrate` without any
 * code change or manual cache bust.
 */
final readonly class SchemaGenerator
{
    /**
     * Populate an MCP ToolInputSchema builder with the writable columns
     * of the configured model.
     *
     * @param  list<string>  $required  Column names that must be supplied
     * @param  array<string, string>  $descriptions  Optional per-column descriptions
     */
    public function applyTo(
        ToolInputSchema $schema,
        string $modelKey,
        array $required = [],
        array $descriptions = [],
    ): ToolInputSchema {
        foreach ($this->writableColumns($modelKey) as $column) {
            $schema->raw($column['name'], $this->toJsonSchemaProperty($column, $descriptions));

            if (in_array($column['name'], $required, true)) {
                $schema->required();
            }
        }

        return $schema;
    }

    /**
     * Return the full JSON Schema (as an array) for the configured model.
     * Used by resources and for cache-warm verification.
     *
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public function jsonSchemaFor(string $modelKey): array
    {
        $properties = [];
        $required = [];

        foreach ($this->writableColumns($modelKey) as $column) {
            $properties[$column['name']] = $this->toJsonSchemaProperty($column);

            if (! $column['nullable'] && $column['default'] === null) {
                $required[] = $column['name'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Return the introspected writable columns for a configured model,
     * excluding hidden and read-only columns.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: mixed}>
     */
    public function writableColumns(string $modelKey): array
    {
        $config = $this->modelConfig($modelKey);
        $excluded = [...$config['hidden'], ...$config['readonly']];

        return array_values(array_filter(
            $this->columns($config['table']),
            static fn (array $col): bool => ! in_array($col['name'], $excluded, true),
        ));
    }

    /**
     * Return the foreign-key columns on the configured model that reference
     * *tenant-scoped* tables (tables with a `user_id` column). Used by the
     * ContextGuard to dynamically enforce ownership on any FK the agent
     * supplies — including FKs added by future migrations.
     *
     * @return array<string, string> [local_column => foreign_table]
     */
    public function tenantScopedForeignKeys(string $modelKey): array
    {
        $config = $this->modelConfig($modelKey);
        $excluded = [...$config['hidden'], ...$config['readonly']];

        $scoped = [];
        foreach ($this->foreignKeys($config['table']) as $column => $foreignTable) {
            // Skip FKs already blocked by the deny-list (e.g. user_id, assignee_id).
            if (in_array($column, $excluded, true)) {
                continue;
            }
            // Only guard references into tables that are themselves tenant-scoped.
            if (Schema::hasColumn($foreignTable, 'user_id')) {
                $scoped[$column] = $foreignTable;
            }
        }

        return $scoped;
    }

    /**
     * Drop all cached schemas. Exposed for tests and the mcp:schema:refresh
     * artisan command.
     */
    public function flush(): void
    {
        $fingerprint = $this->fingerprint();
        foreach (config('mcp.models', []) as $definition) {
            Cache::forget("mcp:schema:{$definition['table']}:{$fingerprint}");
            Cache::forget("mcp:fks:{$definition['table']}:{$fingerprint}");
        }
    }

    /**
     * Introspect and cache the raw column listing for a table.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: mixed}>
     */
    private function columns(string $table): array
    {
        $ttl = (int) config('mcp.schema_cache_ttl', 3600);
        $key = "mcp:schema:{$table}:{$this->fingerprint()}";

        /** @var list<array{name: string, type: string, nullable: bool, default: mixed}> */
        return Cache::remember($key, $ttl, static function () use ($table): array {
            return collect(Schema::getColumns($table))
                ->map(static fn (array $col): array => [
                    'name' => $col['name'],
                    'type' => (string) ($col['type_name'] ?? $col['type']),
                    'nullable' => (bool) $col['nullable'],
                    'default' => $col['default'],
                ])
                ->all();
        });
    }

    /**
     * Introspect and cache the foreign-key constraints for a table.
     * Composite FKs are ignored (Ballistic uses single-column UUIDs only).
     *
     * @return array<string, string> [local_column => foreign_table]
     */
    private function foreignKeys(string $table): array
    {
        $ttl = (int) config('mcp.schema_cache_ttl', 3600);
        $key = "mcp:fks:{$table}:{$this->fingerprint()}";

        /** @var array<string, string> */
        return Cache::remember($key, $ttl, static function () use ($table): array {
            $map = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $columns = (array) ($fk['columns'] ?? []);
                $foreignTable = (string) ($fk['foreign_table'] ?? '');

                if (count($columns) === 1 && $foreignTable !== '') {
                    $map[$columns[0]] = $foreignTable;
                }
            }

            return $map;
        });
    }

    /**
     * Map a DB column descriptor to a JSON Schema property fragment.
     *
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     * @param  array<string, string>  $descriptions
     * @return array<string, mixed>
     */
    private function toJsonSchemaProperty(array $column, array $descriptions = []): array
    {
        $jsonType = $this->mapDbTypeToJsonType($column['type']);

        $property = [
            'type' => $column['nullable'] ? [$jsonType, 'null'] : $jsonType,
            'description' => $descriptions[$column['name']] ?? $this->defaultDescription($column),
        ];

        if (in_array($jsonType, ['integer', 'number'], true)) {
            $property['minimum'] = 0;
        }

        return $property;
    }

    /**
     * Translate a vendor-specific DB type name into a JSON Schema type.
     */
    private function mapDbTypeToJsonType(string $dbType): string
    {
        $dbType = strtolower($dbType);

        return match (true) {
            str_contains($dbType, 'int') => 'integer',
            str_contains($dbType, 'bool') || $dbType === 'tinyint(1)' => 'boolean',
            str_contains($dbType, 'float') || str_contains($dbType, 'double')
                || str_contains($dbType, 'decimal') || str_contains($dbType, 'numeric')
                || str_contains($dbType, 'real') => 'number',
            str_contains($dbType, 'json') => 'object',
            default => 'string',
        };
    }

    /**
     * Build a human-readable fallback description from column metadata.
     *
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     */
    private function defaultDescription(array $column): string
    {
        $parts = ["DB column `{$column['name']}` ({$column['type']})"];

        if ($column['nullable']) {
            $parts[] = 'nullable';
        }

        if ($column['default'] !== null) {
            $parts[] = "defaults to `{$column['default']}`";
        }

        return implode(', ', $parts).'.';
    }

    /**
     * Produce a deterministic fingerprint of the migration state so the
     * schema cache auto-invalidates whenever `artisan migrate` runs.
     *
     * Queried fresh on every call (no process-level memoisation) so the
     * STDIO transport — a long-lived process — picks up new migrations
     * without restart. The `migrations` table is tiny; MAX(batch) is cheap.
     */
    private function fingerprint(): string
    {
        try {
            return (string) DB::table('migrations')->max('batch');
        } catch (\Throwable) {
            return '0';
        }
    }

    /**
     * Resolve and validate the model configuration block.
     *
     * @return array{table: string, hidden: list<string>, readonly: list<string>}
     */
    private function modelConfig(string $modelKey): array
    {
        $config = config("mcp.models.{$modelKey}");

        if (! is_array($config) || ! isset($config['table'])) {
            throw new \InvalidArgumentException(
                "Unknown MCP model key [{$modelKey}]. Register it in config/mcp.php."
            );
        }

        return [
            'table' => (string) $config['table'],
            'hidden' => (array) ($config['hidden'] ?? []),
            'readonly' => (array) ($config['readonly'] ?? []),
        ];
    }
}
