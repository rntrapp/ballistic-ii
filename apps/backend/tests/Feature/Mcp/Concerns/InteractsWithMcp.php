<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Shared helpers for Feature tests that exercise the MCP JSON-RPC surface.
 *
 * Wraps the raw POST /mcp/ballistic endpoint with typed, ergonomic methods
 * so individual test cases read like agent interactions rather than HTTP
 * plumbing. All calls authenticate via Sanctum::actingAs() — the endpoint
 * is wired with auth:sanctum middleware.
 */
trait InteractsWithMcp
{
    protected User $actor;

    protected int $rpcId = 0;

    /**
     * Authenticate as a fresh user and remember them for later calls.
     */
    protected function asUser(?User $user = null): User
    {
        $this->actor = $user ?? User::factory()->create();
        Sanctum::actingAs($this->actor);

        return $this->actor;
    }

    /**
     * Send a raw JSON-RPC 2.0 request to the MCP endpoint.
     *
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp/ballistic', [
            'jsonrpc' => '2.0',
            'id' => ++$this->rpcId,
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * POST a raw request body to the MCP endpoint — used for protocol
     * compliance tests (malformed JSON, scalars, missing fields, etc.).
     */
    protected function rawRpc(string $body): TestResponse
    {
        return $this->call('POST', '/mcp/ballistic', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * Call a tool and decode its content payload.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{response: TestResponse, body: array<string, mixed>, result: array<string, mixed>, content: string, isError: bool}
     */
    protected function callTool(string $name, array $arguments = []): array
    {
        $response = $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        $response->assertOk();
        $body = $response->json();
        $result = $body['result'] ?? [];

        return [
            'response' => $response,
            'body' => $body,
            'result' => $result,
            'content' => $result['content'][0]['text'] ?? '',
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }

    /**
     * Call a tool, assert success, decode the JSON payload inside the
     * ToolResult text content, and return data.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function callToolOk(string $name, array $arguments = []): array
    {
        $r = $this->callTool($name, $arguments);
        $this->assertFalse($r['isError'], "Tool {$name} returned error: {$r['content']}");

        $decoded = json_decode($r['content'], true);
        $this->assertIsArray($decoded, "Tool {$name} did not return JSON: {$r['content']}");
        $this->assertTrue($decoded['ok'] ?? false, "Tool {$name} payload missing ok:true");

        return $decoded['data'] ?? [];
    }

    /**
     * Call a tool and assert it returns an error result.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function callToolError(string $name, array $arguments = []): string
    {
        $r = $this->callTool($name, $arguments);
        $this->assertTrue($r['isError'], "Expected tool {$name} to error but got: {$r['content']}");

        return $r['content'];
    }
}
