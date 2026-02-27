<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PeriodogramInterface;

/**
 * Pure-PHP Lomb-Scargle periodogram.
 *
 * Computes spectral power for irregularly-sampled time series via
 * least-squares fitting of sinusoids at each trial frequency. Unlike
 * an FFT, no uniform sampling assumption is required.
 *
 * Performance target: <400ms for 5000 samples × 100 trial frequencies.
 * Achieved by using plain indexed float arrays and no object/collection
 * overhead inside the inner loops.
 */
final readonly class LombScarglePeriodogram implements PeriodogramInterface
{
    public function analyse(
        array $times,
        array $values,
        float $minPeriod,
        float $maxPeriod,
        int $numFrequencies = 100,
    ): SpectralResult {
        $n = count($times);

        // Degenerate inputs — return a neutral result rather than blow up.
        if ($n < 2 || $n !== count($values) || $numFrequencies < 2) {
            return new SpectralResult(
                dominantPeriodSeconds: ($minPeriod + $maxPeriod) / 2.0,
                power: 0.0,
                phase: 0.0,
                amplitude: 0.0,
                spectrum: [],
            );
        }

        // ─────────────────────────────────────────────────────────────
        // 1. Mean-subtract values (Lomb-Scargle assumes zero-mean data)
        //    and shift times so min time = 0 (numerical stability).
        // ─────────────────────────────────────────────────────────────
        $sumY = 0.0;
        $tMin = $times[0];
        for ($i = 0; $i < $n; $i++) {
            $sumY += $values[$i];
            if ($times[$i] < $tMin) {
                $tMin = $times[$i];
            }
        }
        $meanY = $sumY / $n;

        /** @var float[] $t Zero-shifted times */
        $t = [];
        /** @var float[] $y Mean-subtracted values */
        $y = [];
        $sumYY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $t[$i] = (float) $times[$i] - $tMin;
            $yi = (float) $values[$i] - $meanY;
            $y[$i] = $yi;
            $sumYY += $yi * $yi;
        }

        // Variance (denominator for power normalisation)
        $variance = $sumYY / $n;

        // Constant signal → no periodicity possible
        if ($variance <= 0.0) {
            return new SpectralResult(
                dominantPeriodSeconds: ($minPeriod + $maxPeriod) / 2.0,
                power: 0.0,
                phase: 0.0,
                amplitude: 0.0,
                spectrum: [],
            );
        }

        // ─────────────────────────────────────────────────────────────
        // 2. Build the trial-period grid. Linear spacing in period gives
        //    uniform resolution in "minutes per cycle" which is what the
        //    UI cares about.
        // ─────────────────────────────────────────────────────────────
        $periodStep = ($maxPeriod - $minPeriod) / ($numFrequencies - 1);

        $bestPower = -1.0;
        $bestPeriod = $minPeriod;
        $bestA = 0.0; // cosine coefficient at best frequency
        $bestB = 0.0; // sine coefficient at best frequency

        /** @var list<array{period: float, power: float}> $spectrum */
        $spectrum = [];

        for ($f = 0; $f < $numFrequencies; $f++) {
            $period = $minPeriod + $f * $periodStep;
            $omega = 2.0 * M_PI / $period;
            $twoOmega = 2.0 * $omega;

            // ── Compute the time-offset τ that orthogonalises sin & cos ──
            //    tan(2ωτ) = Σsin(2ωt_i) / Σcos(2ωt_i)
            $sumSin2wt = 0.0;
            $sumCos2wt = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $arg = $twoOmega * $t[$i];
                $sumSin2wt += sin($arg);
                $sumCos2wt += cos($arg);
            }
            $tau = atan2($sumSin2wt, $sumCos2wt) / $twoOmega;

            // ── Accumulate the four sums for P(ω) and the two for phase fit ──
            $sumYCos = 0.0; // Σ y_i · cos(ω(t_i − τ))
            $sumYSin = 0.0; // Σ y_i · sin(ω(t_i − τ))
            $sumCos2 = 0.0; // Σ cos²(ω(t_i − τ))
            $sumSin2 = 0.0; // Σ sin²(ω(t_i − τ))

            // For post-hoc phase extraction (relative to the *original* time axis):
            $sumYCosRaw = 0.0; // Σ y_i · cos(ωt_i)
            $sumYSinRaw = 0.0; // Σ y_i · sin(ωt_i)
            $sumCosCos = 0.0;  // Σ cos²(ωt_i)
            $sumSinSin = 0.0;  // Σ sin²(ωt_i)
            $sumCosSin = 0.0;  // Σ cos(ωt_i)·sin(ωt_i)

            for ($i = 0; $i < $n; $i++) {
                $argTau = $omega * ($t[$i] - $tau);
                $c = cos($argTau);
                $s = sin($argTau);
                $yi = $y[$i];

                $sumYCos += $yi * $c;
                $sumYSin += $yi * $s;
                $sumCos2 += $c * $c;
                $sumSin2 += $s * $s;

                $argRaw = $omega * $t[$i];
                $cr = cos($argRaw);
                $sr = sin($argRaw);
                $sumYCosRaw += $yi * $cr;
                $sumYSinRaw += $yi * $sr;
                $sumCosCos += $cr * $cr;
                $sumSinSin += $sr * $sr;
                $sumCosSin += $cr * $sr;
            }

            // Guard against singular denominators (would only happen with <2 unique t values)
            $cosTerm = $sumCos2 > 1e-12 ? ($sumYCos * $sumYCos) / $sumCos2 : 0.0;
            $sinTerm = $sumSin2 > 1e-12 ? ($sumYSin * $sumYSin) / $sumSin2 : 0.0;

            // Lomb-Scargle power, normalised by total variance so P ∈ [0, 1]
            // where 1 ≈ "this single sinusoid explains all the variance".
            // (n·variance = Σy² since variance = Σy²/n after mean-subtraction.)
            $power = ($cosTerm + $sinTerm) / ($n * $variance);

            $spectrum[] = ['period' => $period, 'power' => $power];

            if ($power > $bestPower) {
                $bestPower = $power;
                $bestPeriod = $period;

                // Least-squares fit y ≈ A·cos(ωt) + B·sin(ωt) at this frequency.
                // Solve the 2×2 normal equations (Cramer's rule).
                $det = $sumCosCos * $sumSinSin - $sumCosSin * $sumCosSin;
                if (abs($det) > 1e-12) {
                    $bestA = ($sumYCosRaw * $sumSinSin - $sumYSinRaw * $sumCosSin) / $det;
                    $bestB = ($sumYSinRaw * $sumCosCos - $sumYCosRaw * $sumCosSin) / $det;
                } else {
                    $bestA = 0.0;
                    $bestB = 0.0;
                }
            }
        }

        // ─────────────────────────────────────────────────────────────
        // 3. Convert (A, B) → amplitude & phase.
        //    y ≈ A·cos(ωt) + B·sin(ωt) = R·cos(ωt + φ)
        //    where R = √(A² + B²) and φ = atan2(-B, A).
        //    The peak of R·cos(ωt + φ) occurs when ωt + φ = 0 (mod 2π).
        //
        //    Caller can then find "a moment where the wave peaks" via:
        //       t_peak = t_min + (−φ / ω)   (adjusted into positive range)
        // ─────────────────────────────────────────────────────────────
        $amplitude = sqrt($bestA * $bestA + $bestB * $bestB);
        $phase = atan2(-$bestB, $bestA);

        return new SpectralResult(
            dominantPeriodSeconds: $bestPeriod,
            power: max(0.0, min(1.0, $bestPower)),
            phase: $phase,
            amplitude: $amplitude,
            spectrum: $spectrum,
        );
    }
}
