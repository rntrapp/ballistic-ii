<?php

declare(strict_types=1);

use App\Services\VelocityForecastingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an effort_score column (Fibonacci scale: 1, 2, 3, 5, 8) to the
     * items table, plus a composite index on (user_id, completed_at) to
     * support high-performance historical velocity lookbacks.
     */
    public function up(): void
    {
        // Add effort_score with an inline CHECK constraint. We use raw SQL because
        // Laravel's schema builder has no CHECK support, and SQLite only accepts
        // CHECK as part of the column definition — not via ALTER TABLE ADD CONSTRAINT.
        // The inline-column-constraint form is portable across PostgreSQL, MySQL & SQLite.
        $type = match (DB::getDriverName()) {
            'pgsql' => 'SMALLINT',
            'mysql', 'mariadb' => 'TINYINT UNSIGNED',
            default => 'INTEGER',
        };

        $allowed = implode(', ', VelocityForecastingService::fibonacciScale());

        DB::statement(
            "ALTER TABLE items ADD COLUMN effort_score {$type} NOT NULL DEFAULT 1 "
            ."CHECK (effort_score IN ({$allowed}))"
        );

        Schema::table('items', function (Blueprint $table) {
            // Composite index for velocity aggregation queries that filter by
            // user and group/filter on completed_at. Covers the hot path of
            // SUM(effort_score) ... WHERE user_id = ? AND completed_at BETWEEN ...
            $table->index(['user_id', 'completed_at'], 'items_user_completed_at_index');

            // Covering index for the upcoming-effort query (user + due window + open status).
            $table->index(['user_id', 'due_date', 'status'], 'items_user_due_status_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the column automatically drops the attached CHECK constraint
     * on every supported driver (PostgreSQL, MySQL, SQLite).
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_user_completed_at_index');
            $table->dropIndex('items_user_due_status_index');
            $table->dropColumn('effort_score');
        });
    }
};
