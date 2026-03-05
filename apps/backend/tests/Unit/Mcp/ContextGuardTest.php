<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Support\ContextGuard;
use App\Mcp\Support\McpAuthorisationException;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContextGuardTest extends TestCase
{
    use RefreshDatabase;

    private ContextGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(ContextGuard::class);
    }

    public function test_user_throws_when_unauthenticated(): void
    {
        $this->expectException(McpAuthorisationException::class);
        $this->guard->user();
    }

    public function test_user_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertTrue($user->is($this->guard->user()));
    }

    public function test_scope_to_owner_constrains_query_to_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Item::factory()->count(3)->inbox()->todo()->create(['user_id' => $owner->id]);
        Item::factory()->count(5)->inbox()->todo()->create(['user_id' => $other->id]);

        $this->actingAs($owner);

        $rows = $this->guard->scopeToOwner(Item::query())->get();

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn (Item $i) => (string) $i->user_id === (string) $owner->id));
    }

    public function test_find_or_deny_returns_owned_model(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->inbox()->create(['user_id' => $owner->id]);

        $this->actingAs($owner);

        $found = $this->guard->findOrDeny(Item::class, (string) $item->id, 'view');
        $this->assertSame((string) $item->id, (string) $found->getKey());
    }

    public function test_find_or_deny_throws_identical_error_for_foreign_and_missing(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        $foreign = Item::factory()->inbox()->create(['user_id' => $victim->id]);

        $this->actingAs($owner);

        // Capture the message for a truly non-existent ID
        try {
            $this->guard->findOrDeny(Item::class, (string) Str::uuid(), 'view');
            $this->fail('Expected McpAuthorisationException for missing row');
        } catch (McpAuthorisationException $missing) {
            $missingMsg = $missing->getMessage();
        }

        // Capture the message for a foreign-owned ID
        try {
            $this->guard->findOrDeny(Item::class, (string) $foreign->id, 'view');
            $this->fail('Expected McpAuthorisationException for foreign row');
        } catch (McpAuthorisationException $denied) {
            $deniedMsg = $denied->getMessage();
        }

        $this->assertSame($missingMsg, $deniedMsg, 'Anti-enumeration: missing and forbidden must yield identical errors.');
        $this->assertStringContainsString('not authorised', $missingMsg);
    }

    public function test_authorise_throws_on_policy_denial(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        $foreign = Project::factory()->create(['user_id' => $victim->id]);

        $this->actingAs($owner);

        $this->expectException(McpAuthorisationException::class);
        $this->guard->authorise('update', $foreign);
    }

    public function test_authorise_passes_for_owner(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->inbox()->create(['user_id' => $owner->id]);

        $this->actingAs($owner);

        $this->guard->authorise('update', $item);
        $this->assertTrue(true); // no exception
    }

    public function test_sanitise_strips_hidden_and_readonly_keys(): void
    {
        $clean = $this->guard->sanitise([
            'title' => 'Keep me',
            'user_id' => 'malicious-override',
            'id' => 'injected-pk',
            'created_at' => '1999-01-01',
            'assignee_id' => 'steal-task',
            'completed_at' => 'force-complete',
        ], 'item');

        $this->assertSame(['title' => 'Keep me'], $clean);
        $this->assertArrayNotHasKey('user_id', $clean);
        $this->assertArrayNotHasKey('id', $clean);
        $this->assertArrayNotHasKey('assignee_id', $clean);
    }

    public function test_sanitise_preserves_unknown_keys(): void
    {
        // Future columns added via migration must pass through without a
        // guard code change — sanitise() is deny-list based, not allow-list.
        $clean = $this->guard->sanitise([
            'title' => 'x',
            'future_column' => 'should survive',
        ], 'item');

        $this->assertArrayHasKey('future_column', $clean);
    }

    // -------------------------------------------------------------------------
    //  Dynamic foreign-key ownership enforcement
    // -------------------------------------------------------------------------

    public function test_assert_owns_references_passes_for_owned_fk(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner);

        $this->guard->assertOwnsReferences(
            ['title' => 'x', 'project_id' => (string) $project->id],
            'item'
        );

        $this->assertTrue(true); // no exception
    }

    public function test_assert_owns_references_throws_for_foreign_fk(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        $foreign = Project::factory()->create(['user_id' => $victim->id]);

        $this->actingAs($owner);

        $this->expectException(McpAuthorisationException::class);
        $this->guard->assertOwnsReferences(
            ['project_id' => (string) $foreign->id],
            'item'
        );
    }

    public function test_assert_owns_references_throws_for_nonexistent_fk(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->expectException(McpAuthorisationException::class);
        $this->guard->assertOwnsReferences(
            ['project_id' => (string) Str::uuid()],
            'item'
        );
    }

    public function test_assert_owns_references_ignores_null_and_absent_fks(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Absent FK → no check.
        $this->guard->assertOwnsReferences(['title' => 'x'], 'item');

        // Explicit null → no check.
        $this->guard->assertOwnsReferences(['project_id' => null], 'item');

        $this->assertTrue(true); // no exceptions
    }

    public function test_assert_owns_references_guards_dynamically_added_fk_without_code_change(): void
    {
        // The headline security test: add a NEW tenant-scoped FK via
        // migration, verify the guard blocks cross-tenant references to it
        // without touching any application code.
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        $this->actingAs($owner);

        // Migrate: new `milestones` table (tenant-scoped) + FK on items.
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
            'migration' => '9999_01_01_000003_add_milestones_to_items',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        // Victim creates a milestone.
        $foreignMilestone = (string) Str::uuid();
        DB::table('milestones')->insert([
            'id' => $foreignMilestone,
            'user_id' => $victim->id,
            'name' => 'Victim milestone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attacker (owner) tries to reference it — must be blocked
        // even though milestone_id did not exist when the guard was written.
        $this->expectException(McpAuthorisationException::class);
        $this->guard->assertOwnsReferences(
            ['title' => 'Steal milestone', 'milestone_id' => $foreignMilestone],
            'item'
        );
    }

    public function test_assert_owns_references_permits_dynamically_added_fk_when_owned(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

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
            'migration' => '9999_01_01_000004_add_milestones_to_items_owned',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        $ownMilestone = (string) Str::uuid();
        DB::table('milestones')->insert([
            'id' => $ownMilestone,
            'user_id' => $owner->id,
            'name' => 'My milestone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->guard->assertOwnsReferences(
            ['milestone_id' => $ownMilestone],
            'item'
        );

        $this->assertTrue(true); // no exception → owned FK accepted
    }
}
