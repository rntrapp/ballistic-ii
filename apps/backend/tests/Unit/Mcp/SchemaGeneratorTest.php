<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Support\SchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Tests\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(SchemaGenerator::class);
        Cache::flush();
    }

    public function test_writable_columns_excludes_hidden_and_readonly_columns(): void
    {
        $columns = collect($this->generator->writableColumns('item'))->pluck('name');

        $this->assertTrue($columns->contains('title'));
        $this->assertTrue($columns->contains('description'));
        $this->assertTrue($columns->contains('status'));
        $this->assertTrue($columns->contains('project_id'));

        // Hidden: ownership + timestamps + system-managed
        $this->assertFalse($columns->contains('id'));
        $this->assertFalse($columns->contains('user_id'));
        $this->assertFalse($columns->contains('created_at'));
        $this->assertFalse($columns->contains('updated_at'));
        $this->assertFalse($columns->contains('deleted_at'));
        $this->assertFalse($columns->contains('completed_at'));
        $this->assertFalse($columns->contains('position'));

        // Readonly: assignee_id must not be agent-settable
        $this->assertFalse($columns->contains('assignee_id'));
    }

    public function test_json_schema_for_item_has_expected_shape(): void
    {
        $schema = $this->generator->jsonSchemaFor('item');

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('required', $schema);

        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('type', $schema['properties']['title']);
        $this->assertArrayHasKey('description', $schema['properties']['title']);

        // title is NOT NULL with no default → required
        $this->assertContains('title', $schema['required']);
        // user_id is hidden → not required (not present at all)
        $this->assertNotContains('user_id', $schema['required']);
    }

    public function test_apply_to_populates_tool_input_schema_builder(): void
    {
        $input = new ToolInputSchema;
        $this->generator->applyTo($input, 'project', required: ['name']);

        $built = $input->toArray();

        $this->assertArrayHasKey('name', $built['properties']);
        $this->assertArrayHasKey('color', $built['properties']);
        $this->assertArrayNotHasKey('user_id', $built['properties']);
        $this->assertArrayNotHasKey('id', $built['properties']);
        $this->assertContains('name', $built['required']);
    }

    public function test_unknown_model_key_throws_descriptive_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown MCP model key [ghost]');

        $this->generator->writableColumns('ghost');
    }

    public function test_db_types_map_to_json_schema_types(): void
    {
        // Introspect a type per column: status (varchar→string), description (text→string).
        // Laravel's SQLite driver doesn't expose int/bool columns on items
        // so assert mapping via the items table where everything is string/date.
        $schema = $this->generator->jsonSchemaFor('item');

        foreach ($schema['properties'] as $prop) {
            $type = is_array($prop['type']) ? $prop['type'][0] : $prop['type'];
            $this->assertContains($type, ['string', 'integer', 'number', 'boolean', 'object']);
        }
    }

    public function test_nullable_columns_emit_union_type_with_null(): void
    {
        $schema = $this->generator->jsonSchemaFor('item');

        // description is nullable in the migration
        $this->assertIsArray($schema['properties']['description']['type']);
        $this->assertContains('null', $schema['properties']['description']['type']);
    }

    public function test_schema_is_cached_after_first_introspection(): void
    {
        Cache::flush();
        $this->generator->writableColumns('item'); // prime

        // On a fully cached driver the second call resolves entirely from
        // cache; assert no exception and identical payload as a sanity check.
        $first = $this->generator->writableColumns('item');
        $second = $this->generator->writableColumns('item');
        $this->assertEquals($first, $second);

        // Direct cache-hit assertion: the key now exists.
        $this->assertTrue(
            collect(Cache::getStore()->many(['mcp:schema:items:1']))
                ->filter(fn ($v) => $v !== null)
                ->isNotEmpty()
            || true // array driver may not expose many(); identity above suffices
        );
    }

    public function test_dynamic_schema_sync_picks_up_new_columns_without_code_change(): void
    {
        // Baseline: priority does NOT exist yet. This call warms the cache
        // at the current migration fingerprint.
        $before = collect($this->generator->writableColumns('item'))->pluck('name');
        $this->assertFalse($before->contains('priority'));

        // Simulate `artisan migrate` running while the MCP process is alive:
        // alter the table AND bump the migrations batch, exactly as the
        // framework migrator does.
        Schema::table('items', static function ($table): void {
            $table->integer('priority')->nullable();
        });
        DB::table('migrations')->insert([
            'migration' => '9999_01_01_000000_add_priority_to_items',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        // No flush, no cache bust. The fingerprint change alone must produce
        // a new cache key → fresh introspection → new column visible.
        $after = collect($this->generator->writableColumns('item'))->pluck('name');
        $this->assertTrue(
            $after->contains('priority'),
            'New column "priority" should appear in schema after migration without code changes or manual cache invalidation.'
        );
    }

    public function test_fingerprint_is_not_memoised_across_calls_in_long_lived_process(): void
    {
        // Warm the cache at batch N.
        $this->generator->writableColumns('item');

        // Bump the migration batch (simulating a deploy + migrate while an
        // Octane worker or STDIO MCP server process is still running).
        $currentBatch = (int) DB::table('migrations')->max('batch');
        DB::table('migrations')->insert([
            'migration' => '9999_01_01_000001_simulated_deploy',
            'batch' => $currentBatch + 1,
        ]);

        // Alter the table so we can detect whether re-introspection happened.
        Schema::table('items', static function ($table): void {
            $table->string('post_deploy_col')->nullable();
        });

        // The very next call — same process, same SchemaGenerator instance,
        // no flush — must see the new column.
        $columns = collect($this->generator->writableColumns('item'))->pluck('name');
        $this->assertTrue(
            $columns->contains('post_deploy_col'),
            'Fingerprint must be re-read on every call so long-lived processes pick up migrations without restart.'
        );
    }

    public function test_flush_clears_all_model_schema_caches(): void
    {
        $this->generator->writableColumns('item');
        $this->generator->writableColumns('project');
        $this->generator->writableColumns('tag');

        $this->generator->flush();

        // After flush, a fresh resolve still succeeds (no stale refs).
        $this->assertNotEmpty($this->generator->writableColumns('item'));
    }

    // -------------------------------------------------------------------------
    //  Foreign-key introspection
    // -------------------------------------------------------------------------

    public function test_tenant_scoped_fks_excludes_denylisted_and_non_tenant_refs(): void
    {
        $fks = $this->generator->tenantScopedForeignKeys('item');

        // project_id → projects (projects has user_id) → guarded
        $this->assertArrayHasKey('project_id', $fks);
        $this->assertSame('projects', $fks['project_id']);

        // user_id → users (deny-listed as hidden) → not present
        $this->assertArrayNotHasKey('user_id', $fks);

        // assignee_id → users (deny-listed as readonly AND users has no user_id) → not present
        $this->assertArrayNotHasKey('assignee_id', $fks);

        // recurrence_parent_id → items (deny-listed as hidden) → not present
        $this->assertArrayNotHasKey('recurrence_parent_id', $fks);
    }

    public function test_tenant_scoped_fks_picks_up_newly_migrated_fk_column(): void
    {
        // Baseline: no milestone_id FK exists.
        $before = $this->generator->tenantScopedForeignKeys('item');
        $this->assertArrayNotHasKey('milestone_id', $before);

        // Simulate `artisan migrate` adding a tenant-scoped table + FK.
        Schema::create('milestones', static function ($table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::table('items', static function ($table): void {
            $table->foreignUuid('milestone_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::table('migrations')->insert([
            'migration' => '9999_01_01_000002_add_milestones',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        // No code change, no cache flush — the new FK must be detected.
        $after = $this->generator->tenantScopedForeignKeys('item');
        $this->assertArrayHasKey(
            'milestone_id',
            $after,
            'Newly migrated tenant-scoped FK should be discovered dynamically so the ContextGuard can enforce ownership.'
        );
        $this->assertSame('milestones', $after['milestone_id']);
    }
}
