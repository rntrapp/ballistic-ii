<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Validation\ValidationException;

/**
 * Thrown when an MCP agent attempts to access or mutate a resource
 * it is not authorised to touch.
 *
 * Extends ValidationException so laravel/mcp's CallTool method catches
 * it and returns a ToolResult::error() instead of a transport-level
 * JSON-RPC error — the agent receives a clean, actionable denial message
 * rather than an opaque -32603 internal error.
 */
final class McpAuthorisationException extends ValidationException
{
    public static function denied(string $reason): self
    {
        return self::withMessages(['authorisation' => [$reason]]);
    }

    public static function unauthenticated(): self
    {
        return self::denied(
            'No authenticated user context. Supply a valid Sanctum bearer token in the Authorization header.'
        );
    }

    public static function forbidden(string $resource, string $action): self
    {
        return self::denied(
            "You are not authorised to {$action} this {$resource}. It may belong to another user or not exist."
        );
    }

    public static function foreignReference(string $column): self
    {
        return self::withMessages([
            $column => ["The referenced {$column} does not exist or does not belong to you."],
        ]);
    }
}
