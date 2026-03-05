<?php

declare(strict_types=1);

use App\Enums\EffortScore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the Fibonacci-scaled effort_score column (with a DB-level CHECK
     * constraint enforcing the enum values) and the composite indexes the
     * VelocityForecastingService relies on for its SQL aggregations.
     *
     * The CHECK constraint is embedded inline in the ADD COLUMN statement
     * because SQLite cannot add a CHECK to an existing table via
     * ALTER TABLE ... ADD CONSTRAINT — it must be attached at column-creation
     * time. MySQL 8.0.16+, PostgreSQL, and SQLite all honour inline CHECK.
     *
     * Both indexes lead with user_id because every velocity query is scoped
     * to a single user — the planner can use them for index-range scans over
     * (user_id, completed_at BETWEEN ...) and (user_id, due_date BETWEEN ...)
     * without touching the heap for filtering.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $allowed = implode(', ', EffortScore::values());
        $default = EffortScore::default()->value;
        $check = "CHECK (effort_score IN ({$allowed}))";

        $columnSql = match ($driver) {
            'mysql', 'mariadb' => "TINYINT UNSIGNED NOT NULL DEFAULT {$default} {$check} AFTER `position`",
            'pgsql' => "SMALLINT NOT NULL DEFAULT {$default} {$check}",
            'sqlite' => "INTEGER NOT NULL DEFAULT {$default} {$check}",
            default => throw new RuntimeException("Unsupported driver: {$driver}"),
        };

        DB::statement("ALTER TABLE items ADD COLUMN effort_score {$columnSql}");

        Schema::table('items', function (Blueprint $table) {
            // Historical velocity lookup:
            //   WHERE user_id = ? AND completed_at >= ? AND completed_at < ?
            //   GROUP BY week(completed_at)
            //   SUM(effort_score)
            $table->index(['user_id', 'completed_at'], 'items_user_completed_idx');

            // Upcoming-effort lookup:
            //   WHERE user_id = ? AND due_date >= ? AND due_date < ?
            //   SUM(effort_score)
            $table->index(['user_id', 'due_date'], 'items_user_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the column drops the inline CHECK constraint with it.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_user_completed_idx');
            $table->dropIndex('items_user_due_idx');
            $table->dropColumn('effort_score');
        });
    }
};
