<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ChronobiologyServiceInterface;
use App\Contracts\PeriodogramInterface;
use App\Enums\CognitivePhase;
use App\Models\CognitiveEvent;
use App\Models\CognitiveProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class ChronobiologyService implements ChronobiologyServiceInterface
{
    /** Minimum number of events required to attempt a spectral analysis. */
    private const MIN_SAMPLES = 10;

    /** Lookback window for events (days). */
    private const LOOKBACK_DAYS = 14;

    /** Profile is considered stale after this many hours. */
    private const STALE_AFTER_HOURS = 24;

    /** Ultradian search band — 60 to 180 minutes in seconds. */
    private const MIN_PERIOD_SECONDS = 3600.0;

    private const MAX_PERIOD_SECONDS = 10800.0;

    private const NUM_TRIAL_FREQUENCIES = 80;

    public function __construct(
        private PeriodogramInterface $periodogram,
    ) {}

    public function computeProfile(User $user): ?CognitiveProfile
    {
        $since = CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS);

        /** @var list<array{occurred_at: string, cognitive_load_score: int}> $rows */
        $rows = CognitiveEvent::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'cognitive_load_score'])
            ->toArray();

        $sampleCount = count($rows);

        if ($sampleCount < self::MIN_SAMPLES) {
            return null;
        }

        // Convert to parallel float arrays — seconds-since-epoch and load score.
        // Use microtime precision by parsing the full 'Y-m-d H:i:s.u' string.
        $times = [];
        $values = [];
        foreach ($rows as $row) {
            $carbon = CarbonImmutable::parse($row['occurred_at']);
            // Carbon's ->timestamp is integer seconds; add fractional microseconds manually.
            $times[] = (float) $carbon->timestamp + ((float) $carbon->micro / 1_000_000.0);
            $values[] = (float) $row['cognitive_load_score'];
        }

        $result = $this->periodogram->analyse(
            $times,
            $values,
            self::MIN_PERIOD_SECONDS,
            self::MAX_PERIOD_SECONDS,
            self::NUM_TRIAL_FREQUENCIES,
        );

        // Derive a "phase anchor" — a concrete moment where the fitted
        // cosine is at its peak — so future projection is a simple
        //    phase_angle = 2π · (now − anchor) / period
        $anchor = $this->derivePhaseAnchor(
            minTime: $times[0],
            periodSeconds: $result->dominantPeriodSeconds,
            phaseRadians: $result->phase,
        );

        $profile = CognitiveProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'dominant_period_seconds' => $result->dominantPeriodSeconds,
                'phase_anchor_at' => $anchor,
                'amplitude' => $result->amplitude,
                'confidence' => $result->power,
                'sample_count' => $sampleCount,
                'computed_at' => CarbonImmutable::now(),
            ],
        );

        return $profile->fresh();
    }

    public function getCurrentPhase(User $user): ?CognitivePhaseSnapshot
    {
        $profile = $user->cognitiveProfile;

        // No profile yet, or stale → recompute.
        if ($profile === null || $this->isStale($profile)) {
            $profile = $this->computeProfile($user);
        }

        if ($profile === null) {
            return null;
        }

        return $this->projectPhaseAt($profile, CarbonImmutable::now());
    }

    public function projectPhaseAt(
        CognitiveProfile $profile,
        CarbonInterface $at,
    ): CognitivePhaseSnapshot {
        $period = $profile->dominant_period_seconds;
        $omega = 2.0 * M_PI / $period;
        $anchor = CarbonImmutable::parse($profile->phase_anchor_at);
        $atImmutable = CarbonImmutable::parse($at);

        // Seconds elapsed since the most recent peak, using microsecond precision.
        $elapsed = $this->secondsBetween($anchor, $atImmutable);

        // Phase angle: where on the cosine wave are we right now?
        $phaseRadians = fmod($elapsed * $omega, 2.0 * M_PI);
        if ($phaseRadians < 0.0) {
            $phaseRadians += 2.0 * M_PI;
        }

        $amplitudeFraction = cos($phaseRadians);
        $phase = CognitivePhase::fromPhaseAngle($phaseRadians);

        // Seconds remaining until phase angle returns to 0 (next peak).
        // If we're already exactly at a peak, the next peak is one full period away.
        $secondsToNextPeak = $phaseRadians < 1e-9
            ? $period
            : (2.0 * M_PI - $phaseRadians) / $omega;

        $nextPeakAt = $atImmutable->addMicroseconds(
            (int) round($secondsToNextPeak * 1_000_000.0)
        );

        return new CognitivePhaseSnapshot(
            phase: $phase,
            dominantPeriodMinutes: $period / 60.0,
            nextPeakAt: $nextPeakAt,
            confidence: $profile->confidence,
            currentAmplitudeFraction: $amplitudeFraction,
            sampleCount: $profile->sample_count,
        );
    }

    /**
     * Convert the periodogram's phase offset into a concrete timestamp
     * at which the cosine wave is at its maximum.
     *
     * The periodogram returns φ such that y(t) ≈ R·cos(ω(t − t_min) + φ).
     * A peak occurs when ω(t − t_min) + φ = 0  →  t = t_min − φ/ω.
     * We then roll forward by whole periods until the anchor is ≥ t_min.
     */
    private function derivePhaseAnchor(
        float $minTime,
        float $periodSeconds,
        float $phaseRadians,
    ): CarbonImmutable {
        $omega = 2.0 * M_PI / $periodSeconds;
        $peakOffset = -$phaseRadians / $omega;

        // Roll the offset into [0, period) so anchor is not before the data window start.
        $peakOffset = fmod($peakOffset, $periodSeconds);
        if ($peakOffset < 0.0) {
            $peakOffset += $periodSeconds;
        }

        $anchorEpoch = $minTime + $peakOffset;

        $seconds = (int) floor($anchorEpoch);
        $micros = (int) round(($anchorEpoch - $seconds) * 1_000_000.0);

        return CarbonImmutable::createFromTimestamp($seconds)->addMicroseconds($micros);
    }

    private function isStale(CognitiveProfile $profile): bool
    {
        $computedAt = CarbonImmutable::parse($profile->computed_at);

        return $computedAt->diffInHours(CarbonImmutable::now()) > self::STALE_AFTER_HOURS;
    }

    /**
     * Microsecond-precision seconds between two Carbon instances.
     */
    private function secondsBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $fromFloat = (float) $from->timestamp + ((float) $from->micro / 1_000_000.0);
        $toFloat = (float) $to->timestamp + ((float) $to->micro / 1_000_000.0);

        return $toFloat - $fromFloat;
    }
}
