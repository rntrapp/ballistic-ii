<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Project;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Mcp\Concerns\InteractsWithMcp;
use Tests\TestCase;

/**
 * Protocol-level compliance tests: JSON-RPC 2.0 envelope, MCP initialize
 * handshake, capability discovery, and performance budget.
 */
final class McpServerTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    public function test_mcp_endpoint_exists_and_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/mcp/ballistic', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ])->assertUnauthorized();
    }

    public function test_initialize_handshake_returns_json_rpc_compliant_envelope(): void
    {
        $this->asUser();

        $response = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'jsonrpc',
                'id',
                'result' => [
                    'protocolVersion',
                    'capabilities' => ['tools', 'resources', 'prompts'],
                    'serverInfo' => ['name', 'version'],
                    'instructions',
                ],
            ]);

        $body = $response->json();
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(1, $body['id']);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
        $this->assertSame(config('mcp.server_name'), $body['result']['serverInfo']['name']);
    }

    public function test_initialize_rejects_unsupported_protocol_version(): void
    {
        $this->asUser();

        $body = $this->rpc('initialize', ['protocolVersion' => '1066-10-14'])->json();

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32602, $body['error']['code']);
    }

    public function test_tools_list_returns_all_registered_tools(): void
    {
        $this->asUser();

        $body = $this->rpc('tools/list')->assertOk()->json();

        $names = collect($body['result']['tools'])->pluck('name');
        $this->assertTrue($names->contains('list-tasks'));
        $this->assertTrue($names->contains('get-task'));
        $this->assertTrue($names->contains('create-task'));
        $this->assertTrue($names->contains('update-task'));
        $this->assertTrue($names->contains('complete-task'));
        $this->assertTrue($names->contains('delete-task'));
        $this->assertTrue($names->contains('list-projects'));
        $this->assertTrue($names->contains('create-project'));
        $this->assertTrue($names->contains('list-tags'));

        // Each tool exposes inputSchema + description
        foreach ($body['result']['tools'] as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function test_tools_list_schema_is_dynamically_generated_from_db(): void
    {
        $this->asUser();

        $body = $this->rpc('tools/list')->json();
        $createTask = collect($body['result']['tools'])->firstWhere('name', 'create-task');

        $this->assertNotNull($createTask);
        $props = $createTask['inputSchema']['properties'];

        // These surface from introspection, not hard-coding.
        $this->assertArrayHasKey('title', $props);
        $this->assertArrayHasKey('description', $props);
        $this->assertArrayHasKey('status', $props);
        $this->assertArrayHasKey('project_id', $props);
        $this->assertArrayHasKey('tag_ids', $props);
        $this->assertContains('title', $createTask['inputSchema']['required']);
    }

    public function test_resources_list_returns_registered_resources(): void
    {
        $this->asUser();

        $body = $this->rpc('resources/list')->assertOk()->json();

        $uris = collect($body['result']['resources'])->pluck('uri');
        $this->assertTrue($uris->contains('ballistic://projects'));
        $this->assertTrue($uris->contains('ballistic://tags'));
        $this->assertTrue($uris->contains('ballistic://schema'));
    }

    public function test_resources_read_returns_user_scoped_projects(): void
    {
        $owner = $this->asUser();
        Project::factory()->count(2)->create(['user_id' => $owner->id]);
        Project::factory()->archived()->create(['user_id' => $owner->id]);
        Project::factory()->create(); // different user

        $body = $this->rpc('resources/read', ['uri' => 'ballistic://projects'])
            ->assertOk()->json();

        $this->assertArrayHasKey('contents', $body['result']);
        $content = json_decode($body['result']['contents'][0]['text'], true);

        $this->assertCount(2, $content); // archived + foreign excluded
        foreach ($content as $project) {
            $this->assertArrayHasKey('id', $project);
            $this->assertArrayHasKey('name', $project);
        }
    }

    public function test_resources_read_schema_contains_all_model_definitions(): void
    {
        $this->asUser();

        $body = $this->rpc('resources/read', ['uri' => 'ballistic://schema'])
            ->assertOk()->json();

        $schema = json_decode($body['result']['contents'][0]['text'], true);
        $this->assertArrayHasKey('definitions', $schema);
        $this->assertArrayHasKey('item', $schema['definitions']);
        $this->assertArrayHasKey('project', $schema['definitions']);
        $this->assertArrayHasKey('tag', $schema['definitions']);
    }

    public function test_resources_read_tags_returns_user_scoped_tags(): void
    {
        $owner = $this->asUser();
        Tag::factory()->count(3)->create(['user_id' => $owner->id]);
        Tag::factory()->count(2)->create(); // foreign

        $body = $this->rpc('resources/read', ['uri' => 'ballistic://tags'])
            ->assertOk()->json();

        $tags = json_decode($body['result']['contents'][0]['text'], true);
        $this->assertCount(3, $tags);
    }

    public function test_unknown_method_returns_json_rpc_method_not_found_error(): void
    {
        $this->asUser();

        $body = $this->rpc('nonsense/method')->json();

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32601, $body['error']['code']);
    }

    // -------------------------------------------------------------------------
    //  JSON-RPC 2.0 error-code compliance
    //
    //  Per RFC: -32700 = Parse error (invalid JSON bytes)
    //           -32600 = Invalid Request (valid JSON, not a Request object)
    //  Valid JSON scalars/arrays are NOT parse errors — they must yield -32600.
    // -------------------------------------------------------------------------

    #[DataProvider('validJsonNonObjectProvider')]
    public function test_valid_json_non_object_body_returns_invalid_request_not_parse_error(string $raw): void
    {
        $this->asUser();

        $body = $this->rawRpc($raw)->json();

        $this->assertArrayHasKey('error', $body, "Body was: {$raw}");
        $this->assertSame(
            -32600,
            $body['error']['code'],
            "Valid JSON '{$raw}' must return -32600 (Invalid Request), not -32700 (Parse error). Got: ".json_encode($body)
        );
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertNull($body['id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validJsonNonObjectProvider(): array
    {
        return [
            'integer' => ['42'],
            'float' => ['3.14'],
            'string' => ['"hello"'],
            'true' => ['true'],
            'false' => ['false'],
            'null' => ['null'],
            'empty array' => ['[]'],
            'integer array' => ['[1,2,3]'],
            'empty object' => ['{}'],
        ];
    }

    #[DataProvider('invalidJsonProvider')]
    public function test_malformed_json_body_returns_parse_error(string $raw): void
    {
        $this->asUser();

        $body = $this->rawRpc($raw)->json();

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(
            -32700,
            $body['error']['code'],
            "Malformed JSON '{$raw}' must return -32700 (Parse error). Got: ".json_encode($body)
        );
        $this->assertSame('2.0', $body['jsonrpc']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJsonProvider(): array
    {
        return [
            'unterminated object' => ['{'],
            'unterminated string' => ['"hello'],
            'stray brace' => ['}'],
            'gibberish' => ['not json at all'],
            'trailing comma' => ['{"a":1,}'],
        ];
    }

    public function test_object_missing_jsonrpc_version_returns_invalid_request(): void
    {
        $this->asUser();

        $body = $this->rawRpc('{"id":1,"method":"ping"}')->json();

        $this->assertSame(-32600, $body['error']['code']);
        $this->assertStringContainsString('2.0', $body['error']['message']);
    }

    public function test_object_with_wrong_jsonrpc_version_returns_invalid_request(): void
    {
        $this->asUser();

        $body = $this->rawRpc('{"jsonrpc":"1.0","id":1,"method":"ping"}')->json();

        $this->assertSame(-32600, $body['error']['code']);
    }

    public function test_object_missing_method_returns_invalid_request(): void
    {
        $this->asUser();

        $body = $this->rawRpc('{"jsonrpc":"2.0","id":1}')->json();

        $this->assertSame(-32600, $body['error']['code']);
        $this->assertStringContainsString('method', strtolower($body['error']['message']));
    }

    public function test_unknown_tool_name_returns_tool_level_error(): void
    {
        $this->asUser();

        $r = $this->callTool('no-such-tool');
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('not found', strtolower($r['content']));
    }

    public function test_ping_returns_empty_result(): void
    {
        $this->asUser();

        $body = $this->rpc('ping')->assertOk()->json();
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('result', $body);
    }

    public function test_handshake_plus_data_exchange_completes_under_100ms(): void
    {
        $this->asUser();

        $start = microtime(true);

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18'])->assertOk();
        $this->rpc('tools/list')->assertOk();
        $this->callToolOk('list-tasks');

        $elapsed = (microtime(true) - $start) * 1000.0;

        $this->assertLessThan(100.0, $elapsed, sprintf(
            'Handshake + list in %.2fms exceeds 100ms budget', $elapsed
        ));
    }
}
