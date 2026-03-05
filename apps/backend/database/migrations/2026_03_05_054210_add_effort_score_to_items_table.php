<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an effort_score column for velocity forecasting. Values follow the
     * Fibonacci planning-poker scale (1, 2, 3, 5, 8). Defaulting to 1 keeps
     * legacy items participating in velocity maths without inflating load.
     *
     * A composite index on (user_id, status, completed_at) backs the
     * VelocityForecastingService weekly aggregation query, which filters on
     * all three columns and must stay fast as history grows.
     */
    public function up(): void
    {
        // CHECK inlined on the ADD COLUMN so sqlite (tests) and pgsql (prod)
        // both enforce the Fibonacci domain at the storage layer. Sqlite cannot
        // ALTER TABLE ADD CONSTRAINT, so a separate DDL statement would silently
        // protect prod while leaving the test suite blind to violations.
        DB::statement(
            'ALTER TABLE items ADD COLUMN effort_score SMALLINT NOT NULL DEFAULT 1 '
            .'CONSTRAINT items_effort_score_fibonacci CHECK (effort_score IN (1, 2, 3, 5, 8))'
        );

        Schema::table('items', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'completed_at'], 'items_velocity_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_velocity_lookup_index');
            $table->dropColumn('effort_score');
        });
    }
};
