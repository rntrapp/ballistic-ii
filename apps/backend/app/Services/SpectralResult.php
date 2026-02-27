<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Immutable value object holding the result of a periodogram analysis.
 */
final readonly class SpectralResult
{
    /**
     * @param  list<array{period: float, power: float}>  $spectrum
     *                                                              Full power spectrum — useful for debugging/plotting.
     *                                                              Each entry: {period: seconds, power: normalised spectral power 0-1}
     */
    public function __construct(
        public float $dominantPeriodSeconds,
        public float $power,      // Normalised power at dominant frequency (0-1)
        public float $phase,      // Radians — offset so y peaks when ωt + phase = 0 (mod 2π)
        public float $amplitude,  // Fitted sine amplitude at dominant frequency
        public array $spectrum,
    ) {}
}
