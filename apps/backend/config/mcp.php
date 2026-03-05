<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | Name and semantic version reported to MCP clients during the initialize
    | handshake. Bump the version whenever the tool surface changes shape so
    | agents can invalidate their cached tool definitions.
    |
    */

    'server_name' => env('MCP_SERVER_NAME', 'Ballistic MCP'),
    'server_version' => env('MCP_SERVER_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------------
    | Dynamic Schema Introspection
    |--------------------------------------------------------------------------
    |
    | Maps Eloquent models to their backing tables for automatic JSON Schema
    | generation. When a migration adds/removes a column, the MCP tool schema
    | reflects the change on the next request without a code deploy.
    |
    */

    'models' => [
        'item' => [
            'table' => 'items',
            'hidden' => [
                'id', 'user_id', 'deleted_at', 'created_at', 'updated_at',
                'completed_at', 'recurrence_parent_id', 'position',
            ],
            'readonly' => ['assignee_id'],
        ],
        'project' => [
            'table' => 'projects',
            'hidden' => [
                'id', 'user_id', 'deleted_at', 'created_at', 'updated_at',
                'archived_at',
            ],
            'readonly' => [],
        ],
        'tag' => [
            'table' => 'tags',
            'hidden' => [
                'id', 'user_id', 'created_at', 'updated_at',
            ],
            'readonly' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Cache
    |--------------------------------------------------------------------------------
    |
    | Introspected schemas are cached to meet the <100ms handshake budget.
    | The cache key includes a fingerprint of applied migrations so the
    | cache is automatically busted whenever migrations are run.
    |
    */

    'schema_cache_ttl' => (int) env('MCP_SCHEMA_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Guardrail Pagination
    |--------------------------------------------------------------------------
    |
    | Hard cap on list-tool result size to keep agent context windows sane
    | and bound memory pressure per tool call.
    |
    */

    'max_list_results' => (int) env('MCP_MAX_LIST_RESULTS', 50),
];
