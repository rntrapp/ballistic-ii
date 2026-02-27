<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\CognitiveProfile;
use App\Models\User;
use App\Services\CognitivePhaseSnapshot;
use Carbon\CarbonInterface;

interface ChronobiologyServiceInterface
{
    /**
     * Run the full spectral pipeline over the user's last 14 days of
     * cognitive events and persist (upsert) a CognitiveProfile.
     *
     * Returns null when there are fewer than the minimum required events.
     */
    public function computeProfile(User $user): ?CognitiveProfile;

    /**
     * Resolve the user's *current* cognitive phase by reading the cached
     * profile (or recomputing if stale/missing) and projecting forward
     * to now(). Returns null when no profile could be established.
     */
    public function getCurrentPhase(User $user): ?CognitivePhaseSnapshot;

    /**
     * Pure projection: given a known profile and an arbitrary timestamp,
     * compute the phase angle, current amplitude fraction, and next peak.
     * Used by both getCurrentPhase() and tests.
     */
    public function projectPhaseAt(
        CognitiveProfile $profile,
        CarbonInterface $at,
    ): CognitivePhaseSnapshot;
}
