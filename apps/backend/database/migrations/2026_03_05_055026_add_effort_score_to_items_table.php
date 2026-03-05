<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fibonacci effort scale, frozen at migration-authoring time.
     * Mirrors App\Enums\EffortScore — but inlined here because migrations
     * must be immutable: if the enum grows a `13` case next year, existing
     * rows constrained by the old CHECK would be unaffected anyway.
     */
    private const array FIBONACCI = [1, 2, 3, 5, 8];

    /**
     * Run the migrations.
     *
     * Adds a Fibonacci-scale effort score with a DB-level CHECK constraint,
     * plus two composite indexes covering the forecast service's hot paths:
     *
     *   (user_id, completed_at) — historical aggregation. Seek to
     *       (user, now − 12 weeks), range-scan to (user, start of current
     *       week), GROUP BY DATE. Without this the 12-week velocity query is
     *       a full scan of every item the user has ever owned.
     *
     *   (user_id, due_date) — upcoming-load sum. Seek to (user, today),
     *       range-scan to (user, today + 7 days), SUM. The pre-existing
     *       single-column due_date index is useless for a multi-tenant table:
     *       the planner must pick either "all of this user's items" or
     *       "everyone's items due this week", and neither is selective.
     *       status is left out of the key — NOT IN cannot be range-scanned,
     *       and the residual filter runs on ~7 days of one user's items.
     *
     * The column is added via raw DDL because SQLite cannot attach a CHECK
     * constraint to an existing column — only inline with ADD COLUMN.
     * PostgreSQL accepts the same syntax, so one statement covers both.
     */
    public function up(): void
    {
        $in = implode(', ', self::FIBONACCI);
        $default = self::FIBONACCI[0];

        // SMALLINT maps to INTEGER affinity in SQLite and 2-byte int in
        // PostgreSQL — ample headroom for a max value of 8.
        DB::statement(<<<SQL
            ALTER TABLE items
            ADD COLUMN effort_score SMALLINT NOT NULL DEFAULT {$default}
            CHECK (effort_score IN ({$in}))
        SQL);

        Schema::table('items', function (Blueprint $table) {
            $table->index(['user_id', 'completed_at']);
            $table->index(['user_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'due_date']);
            $table->dropIndex(['user_id', 'completed_at']);
            $table->dropColumn('effort_score');
        });
    }
};
