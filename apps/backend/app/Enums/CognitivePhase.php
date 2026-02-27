<?php

declare(strict_types=1);

namespace App\Enums;

enum CognitivePhase: string
{
    case Peak = 'peak';
    case Trough = 'trough';
    case Recovery = 'recovery';

    /**
     * Map a phase angle (radians, relative to a cosine wave where
     * 0 = peak and π = trough) to a CognitivePhase case.
     *
     * The wave is partitioned into thirds by amplitude:
     *   cos(θ) ≥ 0.5   →  Peak     (top third)
     *   cos(θ) ≤ -0.5  →  Trough   (bottom third)
     *   otherwise      →  Recovery (rising/falling transitions)
     *
     * Which corresponds to angle bands:
     *   Peak:     [0, π/3) ∪ [5π/3, 2π)
     *   Trough:   [2π/3, 4π/3)
     *   Recovery: [π/3, 2π/3) ∪ [4π/3, 5π/3)
     */
    public static function fromPhaseAngle(float $radians): self
    {
        $twoPi = 2.0 * M_PI;
        // Normalise to [0, 2π)
        $theta = fmod($radians, $twoPi);
        if ($theta < 0.0) {
            $theta += $twoPi;
        }

        $cosine = cos($theta);

        if ($cosine >= 0.5) {
            return self::Peak;
        }

        if ($cosine <= -0.5) {
            return self::Trough;
        }

        return self::Recovery;
    }
}
