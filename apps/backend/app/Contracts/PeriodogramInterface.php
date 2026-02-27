<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\SpectralResult;

interface PeriodogramInterface
{
    /**
     * Perform spectral analysis on irregularly-sampled time-series data.
     *
     * Implementations must NOT assume uniform sample spacing — a standard
     * FFT is mathematically invalid here. The Lomb-Scargle periodogram
     * computes spectral power at arbitrary trial frequencies via
     * least-squares sine/cosine fitting.
     *
     * @param  float[]  $times  Seconds since epoch (irregular spacing)
     * @param  float[]  $values  Amplitude samples (e.g. cognitive load scores)
     * @param  float  $minPeriod  Smallest trial period in seconds (e.g. 3600 = 60 min)
     * @param  float  $maxPeriod  Largest trial period in seconds (e.g. 10800 = 180 min)
     * @param  int  $numFrequencies  Number of trial frequencies to sample
     */
    public function analyse(
        array $times,
        array $values,
        float $minPeriod,
        float $maxPeriod,
        int $numFrequencies = 100,
    ): SpectralResult;
}
