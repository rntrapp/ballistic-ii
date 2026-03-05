<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ContextGuard;
use App\Mcp\Support\SchemaGenerator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Shared infrastructure for MCP tools: security guard, schema generator,
 * validation helper, and response helper. Keeps tool classes lean.
 */
trait InteractsWithContext
{
    /**
     * Resolve the context-aware security guard.
     */
    protected function guard(): ContextGuard
    {
        return app(ContextGuard::class);
    }

    /**
     * Resolve the dynamic schema generator.
     */
    protected function schemas(): SchemaGenerator
    {
        return app(SchemaGenerator::class);
    }

    /**
     * Validate tool arguments against Laravel validation rules.
     * Throws ValidationException which the MCP CallTool handler
     * converts into a ToolResult::error() for the agent.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed> Validated data
     *
     * @throws ValidationException
     */
    protected function validate(array $arguments, array $rules, array $messages = []): array
    {
        return Validator::make($arguments, $rules, $messages)->validate();
    }

    /**
     * Wrap a JSON-serialisable payload in a successful ToolResult.
     *
     * @param  array<string, mixed>  $data
     */
    protected function success(array $data, ?string $message = null): ToolResult
    {
        return ToolResult::json(array_filter([
            'ok' => true,
            'message' => $message,
            'data' => $data,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Hard limit on list results, configurable via config/mcp.php.
     */
    protected function limit(): int
    {
        return (int) config('mcp.max_list_results', 50);
    }
}
