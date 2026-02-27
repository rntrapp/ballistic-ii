<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores microsecond-precision timestamps of task-state transitions
     * for spectral analysis of the user's ultradian rhythm.
     */
    public function up(): void
    {
        Schema::create('cognitive_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('event_type', 20); // started | completed
            $table->unsignedTinyInteger('cognitive_load_score'); // 1-10
            // Microsecond precision (6 decimal places). Postgres native; SQLite stores TEXT but preserves value.
            $table->timestamp('occurred_at', 6);
            $table->timestamps();

            // Hot path: range scan over last 14 days for a given user
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'event_type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cognitive_events');
    }
};
