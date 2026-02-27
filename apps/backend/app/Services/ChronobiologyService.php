<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CognitiveEvent;
use Illuminate\Support\Carbon;

final class ChronobiologyService
{
    /** Minimum number of events required for meaningful analysis. */
    private const int MIN_EVENTS = 4;

    /** Number of frequency steps for the Lomb-Scargle periodogram. */
    private const int FREQUENCY_STEPS = 200;

    /** Minimum period to test in minutes (1 hour). */
    private const float MIN_PERIOD_MINUTES = 60.0;

    /** Maximum period to test in minutes (4 hours). */
    private const float MAX_PERIOD_MINUTES = 240.0;

    /**
     * Maximum data points fed into the periodogram.
     *
     * Nyquist for a 60-min minimum period requires 1 sample per 30 min.
     * 1500 points over 14 days ≈ 1 sample every 13 min — well above Nyquist.
     * Decimation keeps the algorithm O(MAX_POINTS × FREQUENCY_STEPS).
     */
    private const int MAX_PERIODOGRAM_POINTS = 1500;

    /** Number of days to look back for analysis data. */
    private const int LOOKBACK_DAYS = 14;

    /**
     * Analyse the user's cognitive rhythm and return phase information.
     *
     * @return array{
     *     dominant_cycle_minutes: float,
     *     current_phase: string,
     *     phase_progress: float,
     *     next_peak_at: string,
     *     confidence_score: float,
     *     today_events: array<int, array{recorded_at: string, cognitive_load_score: int, event_type: string}>,
     * }
     */
    public function analyse(string $userId): array
    {
        $since = Carbon::now()->subDays(self::LOOKBACK_DAYS);

        $events = CognitiveEvent::where('user_id', $userId)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cognitive_load_score', 'event_type']);

        $todayEvents = $events
            ->filter(fn (CognitiveEvent $e): bool => $e->recorded_at->isToday())
            ->map(fn (CognitiveEvent $e): array => [
                'recorded_at' => $e->recorded_at->toIso8601String(),
                'cognitive_load_score' => $e->cognitive_load_score,
                'event_type' => $e->event_type,
            ])
            ->values()
            ->all();

        if ($events->count() < self::MIN_EVENTS) {
            return $this->defaultResult($todayEvents);
        }

        // Convert events to time-series: seconds since first event, score values
        $firstTimestamp = $events->first()->recorded_at;
        $times = [];
        $values = [];

        foreach ($events as $event) {
            $times[] = (float) $event->recorded_at->diffInSeconds($firstTimestamp);
            $values[] = (float) $event->cognitive_load_score;
        }

        // Check for zero variance
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= count($values);

        if ($variance < 1e-10) {
            return $this->defaultResult($todayEvents);
        }

        // Convert times to minutes for easier frequency interpretation
        $timesMinutes = array_map(fn (float $t): float => $t / 60.0, $times);

        // Run Lomb-Scargle periodogram
        $spectrum = $this->lombScargle($timesMinutes, $values, $mean);

        if ($spectrum === null) {
            return $this->defaultResult($todayEvents);
        }

        $dominantCycleMinutes = $spectrum['period'];
        $confidence = $spectrum['confidence'];
        $dominantFrequency = $spectrum['frequency'];

        // Determine current phase
        $now = Carbon::now();
        $nowMinutes = (float) $now->diffInSeconds($firstTimestamp) / 60.0;
        $phase = fmod($nowMinutes * $dominantFrequency * 2.0 * M_PI, 2.0 * M_PI);

        if ($phase < 0) {
            $phase += 2.0 * M_PI;
        }

        $currentPhase = $this->mapPhase($phase);
        $phaseProgress = $phase / (2.0 * M_PI);

        // Calculate next peak time.
        // Peak region spans [5π/3, 2π] ∪ [0, π/3]; its entry point is 5π/3.
        $peakEntry = 5.0 / 3.0 * M_PI;

        if ($phase >= $peakEntry) {
            // Currently in the late Peak region — next fresh Peak entry is a full
            // cycle away minus the small arc already past 5π/3.
            $phaseDistance = 2.0 * M_PI - $phase + $peakEntry;
        } else {
            // Advance forward to the next 5π/3 boundary.
            $phaseDistance = $peakEntry - $phase;
        }

        $minutesToNextPeak = ($phaseDistance / (2.0 * M_PI)) * $dominantCycleMinutes;

        if ($minutesToNextPeak < 1.0) {
            $minutesToNextPeak += $dominantCycleMinutes;
        }

        $nextPeakAt = $now->copy()->addMinutes((int) round($minutesToNextPeak))->toIso8601String();

        return [
            'dominant_cycle_minutes' => round($dominantCycleMinutes, 1),
            'current_phase' => $currentPhase,
            'phase_progress' => round($phaseProgress, 3),
            'next_peak_at' => $nextPeakAt,
            'confidence_score' => round($confidence, 3),
            'today_events' => $todayEvents,
        ];
    }

    /**
     * Lomb-Scargle periodogram for unevenly-spaced time-series data.
     *
     * Tests frequencies in the ultradian range (1h to 4h periods).
     *
     * @param  float[]  $times  Time points in minutes
     * @param  float[]  $values  Signal values
     * @param  float  $mean  Pre-computed mean of values
     * @return array{period: float, frequency: float, confidence: float}|null
     */
    private function lombScargle(array $times, array $values, float $mean): ?array
    {
        $n = count($times);

        // Decimate when oversampled — take every kth point to stay within budget.
        if ($n > self::MAX_PERIODOGRAM_POINTS) {
            $step = (int) ceil($n / self::MAX_PERIODOGRAM_POINTS);
            $decTimes = [];
            $decValues = [];

            for ($j = 0; $j < $n; $j += $step) {
                $decTimes[] = $times[$j];
                $decValues[] = $values[$j];
            }

            $times = $decTimes;
            $values = $decValues;
            $n = count($times);
            $mean = array_sum($values) / $n;
        }

        // Centre the signal
        $centred = array_map(fn (float $v): float => $v - $mean, $values);

        // Frequency range: 1/MAX_PERIOD to 1/MIN_PERIOD (cycles per minute)
        $fMin = 1.0 / self::MAX_PERIOD_MINUTES;
        $fMax = 1.0 / self::MIN_PERIOD_MINUTES;
        $fStep = ($fMax - $fMin) / (self::FREQUENCY_STEPS - 1);

        $bestPower = 0.0;
        $bestFrequency = 0.0;
        $totalPower = 0.0;

        // Pre-allocate trig cache arrays once.
        $sinT = array_fill(0, $n, 0.0);
        $cosT = array_fill(0, $n, 0.0);

        for ($i = 0; $i < self::FREQUENCY_STEPS; $i++) {
            $freq = $fMin + $i * $fStep;
            $omega = 2.0 * M_PI * $freq;

            // First pass: compute sin(ωt) and cos(ωt) once per data point.
            // Derive sin(2ωt) and cos(2ωt) via double-angle identities.
            $sin2Sum = 0.0;
            $cos2Sum = 0.0;

            for ($j = 0; $j < $n; $j++) {
                $arg = $omega * $times[$j];
                $s = sin($arg);
                $c = cos($arg);
                $sinT[$j] = $s;
                $cosT[$j] = $c;
                // sin(2x) = 2·sin(x)·cos(x), cos(2x) = cos²(x) − sin²(x)
                $sin2Sum += 2.0 * $s * $c;
                $cos2Sum += $c * $c - $s * $s;
            }

            $tau = atan2($sin2Sum, $cos2Sum) / (2.0 * $omega);

            // Angle-subtraction constants for the τ offset.
            $cosTau = cos($omega * $tau);
            $sinTau = sin($omega * $tau);

            // Second pass: spectral power using cached trig and angle subtraction.
            // cos(ω(t−τ)) = cos(ωt)·cos(ωτ) + sin(ωt)·sin(ωτ)
            // sin(ω(t−τ)) = sin(ωt)·cos(ωτ) − cos(ωt)·sin(ωτ)
            $cosSum = 0.0;
            $sinSum = 0.0;
            $cos2SumNorm = 0.0;
            $sin2SumNorm = 0.0;

            for ($j = 0; $j < $n; $j++) {
                $cosVal = $cosT[$j] * $cosTau + $sinT[$j] * $sinTau;
                $sinVal = $sinT[$j] * $cosTau - $cosT[$j] * $sinTau;

                $cosSum += $centred[$j] * $cosVal;
                $sinSum += $centred[$j] * $sinVal;
                $cos2SumNorm += $cosVal * $cosVal;
                $sin2SumNorm += $sinVal * $sinVal;
            }

            $power = 0.0;

            if ($cos2SumNorm > 1e-10) {
                $power += ($cosSum * $cosSum) / $cos2SumNorm;
            }

            if ($sin2SumNorm > 1e-10) {
                $power += ($sinSum * $sinSum) / $sin2SumNorm;
            }

            $power *= 0.5;
            $totalPower += $power;

            if ($power > $bestPower) {
                $bestPower = $power;
                $bestFrequency = $freq;
            }
        }

        if ($bestFrequency < 1e-10) {
            return null;
        }

        $avgPower = $totalPower / self::FREQUENCY_STEPS;
        $confidence = $avgPower > 1e-10 ? min($bestPower / ($avgPower * self::FREQUENCY_STEPS * 0.05), 1.0) : 0.0;

        return [
            'period' => 1.0 / $bestFrequency,
            'frequency' => $bestFrequency,
            'confidence' => $confidence,
        ];
    }

    /**
     * Map a phase angle (0 to 2π) to a cognitive phase label.
     *
     * 0 to π/3       → Peak
     * π/3 to 2π/3    → Recovery
     * 2π/3 to 4π/3   → Trough
     * 4π/3 to 5π/3   → Recovery
     * 5π/3 to 2π     → Peak
     */
    private function mapPhase(float $phase): string
    {
        $piThird = M_PI / 3.0;

        if ($phase < $piThird) {
            return 'Peak';
        }

        if ($phase < 2.0 * $piThird) {
            return 'Recovery';
        }

        if ($phase < 4.0 * $piThird) {
            return 'Trough';
        }

        if ($phase < 5.0 * $piThird) {
            return 'Recovery';
        }

        return 'Peak';
    }

    /**
     * Default result when there is insufficient data for analysis.
     *
     * @param  array<int, array{recorded_at: string, cognitive_load_score: int, event_type: string}>  $todayEvents
     * @return array{
     *     dominant_cycle_minutes: float,
     *     current_phase: string,
     *     phase_progress: float,
     *     next_peak_at: string,
     *     confidence_score: float,
     *     today_events: array<int, array{recorded_at: string, cognitive_load_score: int, event_type: string}>,
     * }
     */
    private function defaultResult(array $todayEvents): array
    {
        return [
            'dominant_cycle_minutes' => 90.0,
            'current_phase' => 'Peak',
            'phase_progress' => 0.0,
            'next_peak_at' => Carbon::now()->addMinutes(90)->toIso8601String(),
            'confidence_score' => 0.0,
            'today_events' => $todayEvents,
        ];
    }
}
