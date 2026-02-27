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
     * Caches the expensive spectral-analysis result so live phase
     * projection is a cheap cosine computation rather than a full
     * periodogram on every request.
     */
    public function up(): void
    {
        Schema::create('cognitive_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->float('dominant_period_seconds'); // e.g. 6000.0 = 100 min cycle
            // A moment in time where cos(ω·t + φ) = 1, i.e. a peak.
            // Projecting forward from this anchor gives the current phase angle.
            $table->timestamp('phase_anchor_at', 6);
            $table->float('amplitude');
            $table->float('confidence'); // Normalised spectral power at dominant freq (0-1)
            $table->unsignedInteger('sample_count');
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cognitive_profiles');
    }
};
