<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Context-aware security guard for MCP tool calls.
 *
 * Every read and write funnelled through this guard is scoped to the
 * authenticated Sanctum user. Agent-supplied IDs are *never* trusted
 * as proof of access — every lookup re-verifies ownership through the
 * existing Policy layer, guaranteeing a hallucinated ID for another
 * tenant's row yields a denial rather than a leak.
 */
final readonly class ContextGuard
{
    /**
     * Resolve the authenticated user or throw.
     */
    public function user(): User
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user instanceof User) {
            throw McpAuthorisationException::unauthenticated();
        }

        return $user;
    }

    /**
     * Constrain a query to rows owned by the authenticated user.
     * This is the mandatory entry-point for all list/read tools.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeToOwner(Builder $query, string $ownerColumn = 'user_id'): Builder
    {
        return $query->where($ownerColumn, $this->user()->id);
    }

    /**
     * Locate a model by primary key, aborting if the authenticated user
     * fails the policy check for the given ability. Returns a denial
     * identical to "not found" so agents cannot enumerate foreign IDs.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public function findOrDeny(string $modelClass, string $id, string $ability): Model
    {
        /** @var TModel|null $model */
        $model = $modelClass::query()->find($id);
        $resource = strtolower(class_basename($modelClass));

        if ($model === null) {
            throw McpAuthorisationException::forbidden($resource, $ability);
        }

        $this->authorise($ability, $model);

        return $model;
    }

    /**
     * Delegate to the Gate/Policy layer; throw an MCP-aware denial
     * instead of Laravel's AuthorizationException on failure.
     */
    public function authorise(string $ability, Model $model): void
    {
        if (Gate::forUser($this->user())->denies($ability, $model)) {
            throw McpAuthorisationException::forbidden(
                strtolower(class_basename($model)),
                $ability,
            );
        }
    }

    /**
     * Strip any keys the agent must never set directly (ownership columns,
     * timestamps, primary keys). Prevents privilege escalation via crafted
     * payloads like {"user_id": "<victim>"}.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function sanitise(array $arguments, string $modelKey): array
    {
        $config = config("mcp.models.{$modelKey}", []);
        $forbidden = [...(array) ($config['hidden'] ?? []), ...(array) ($config['readonly'] ?? [])];

        return array_diff_key($arguments, array_flip($forbidden));
    }

    /**
     * Dynamically verify every foreign-key value in the payload references a
     * row owned by the authenticated user.
     *
     * Covers any tenant-scoped FK introspected from the live schema — including
     * columns added by future migrations — without hardcoding per-column rules.
     * This is the counterpart to the dynamic schema: if a column can be written,
     * its referential ownership is also checked.
     *
     * @param  array<string, mixed>  $payload  The (already sanitised) write payload
     *
     * @throws McpAuthorisationException when any FK value references a foreign-owned or non-existent row
     */
    public function assertOwnsReferences(array $payload, string $modelKey): void
    {
        $user = $this->user();

        foreach (app(SchemaGenerator::class)->tenantScopedForeignKeys($modelKey) as $column => $foreignTable) {
            $value = $payload[$column] ?? null;

            if ($value === null) {
                continue;
            }

            $owned = DB::table($foreignTable)
                ->where('id', $value)
                ->where('user_id', $user->id)
                ->exists();

            if (! $owned) {
                throw McpAuthorisationException::foreignReference($column);
            }
        }
    }
}
