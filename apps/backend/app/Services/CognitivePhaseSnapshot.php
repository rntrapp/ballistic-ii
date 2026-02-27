<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CognitivePhase;
use Carbon\CarbonImmutable;

/**
 * Immutable DTO describing where the user currently sits on their
 * ultradian cycle, derived from the cached CognitiveProfile.
 */
final readonly class CognitivePhaseSnapshot
{
    public function __construct(
        public CognitivePhase $phase,
        public float $dominantPeriodMinutes,
        public CarbonImmutable $nextPeakAt,
        public float $confidence,
        /** -1..1, the cosine value at the current moment */
        public float $currentAmplitudeFraction,
        public int $sampleCount,
    ) {}
}
