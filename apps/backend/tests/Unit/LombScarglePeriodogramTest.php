<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LombScarglePeriodogram;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests — no Laravel bootstrap required, just PHPUnit.
 * These validate the DSP core in isolation from the rest of the app.
 */
final class LombScarglePeriodogramTest extends TestCase
{
    private LombScarglePeriodogram $periodogram;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->periodogram = new LombScarglePeriodogram;
    }

    /**
     * The headline acceptance criterion: a 100-minute synthetic cycle
     * must be detected within ±5 minutes.
     */
    public function test_identifies_100_minute_cycle_from_synthetic_sine(): void
    {
        $periodSeconds = 6000.0; // 100 minutes
        $baseTime = 1_700_000_000.0;
        $twoDays = 2 * 86400;

        // Generate 50 irregularly-spaced samples across two days.
        mt_srand(42);
        $times = [];
        $values = [];
        for ($i = 0; $i < 50; $i++) {
            $t = $baseTime + (mt_rand(0, $twoDays * 1000) / 1000.0);
            $times[] = $t;
            $values[] = 5.0 + 3.0 * cos(2.0 * M_PI * $t / $periodSeconds);
        }

        $result = $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 100);

        $detectedMinutes = $result->dominantPeriodSeconds / 60.0;
        $this->assertGreaterThanOrEqual(95.0, $detectedMinutes);
        $this->assertLessThanOrEqual(105.0, $detectedMinutes);
        $this->assertGreaterThan(0.5, $result->power, 'Clean synthetic signal should have high spectral power');
    }

    /**
     * Same synthetic signal but with additive noise — detection should
     * still land within ±10% of the true period.
     */
    public function test_identifies_dominant_frequency_with_noise(): void
    {
        $periodSeconds = 5400.0; // 90 minutes
        $baseTime = 1_700_000_000.0;

        mt_srand(123);
        $times = [];
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $t = $baseTime + mt_rand(0, 172_800);
            $noise = (mt_rand(-100, 100) / 100.0); // ±1.0
            $times[] = (float) $t;
            $values[] = 5.0 + 3.0 * cos(2.0 * M_PI * $t / $periodSeconds) + $noise;
        }

        $result = $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 100);

        $trueMinutes = 90.0;
        $detectedMinutes = $result->dominantPeriodSeconds / 60.0;
        $tolerance = $trueMinutes * 0.10;

        $this->assertEqualsWithDelta($trueMinutes, $detectedMinutes, $tolerance);
    }

    /**
     * With only 3 samples we can't really find a meaningful cycle,
     * but the function must not crash.
     */
    public function test_handles_minimum_sample_count(): void
    {
        $times = [1000.0, 2000.0, 3500.0];
        $values = [3.0, 7.0, 5.0];

        $result = $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 20);

        $this->assertGreaterThanOrEqual(3600.0, $result->dominantPeriodSeconds);
        $this->assertLessThanOrEqual(10800.0, $result->dominantPeriodSeconds);
        $this->assertNotEmpty($result->spectrum);
    }

    /**
     * Performance budget: 5000 samples × 80 trial frequencies must
     * complete in under 400ms on commodity hardware.
     */
    public function test_performance_5000_points_under_400ms(): void
    {
        mt_srand(7);
        $times = [];
        $values = [];
        for ($i = 0; $i < 5000; $i++) {
            $times[] = (float) mt_rand(0, 14 * 86400);
            $values[] = (float) mt_rand(1, 10);
        }

        $start = microtime(true);
        $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 80);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.4, $elapsed, "Periodogram took {$elapsed}s — performance budget is 0.4s");
    }

    /**
     * When the synthetic data peaks at a known moment, the derived phase
     * should place a peak within ±period/20 of that moment.
     */
    public function test_phase_anchor_aligns_with_known_peak(): void
    {
        $periodSeconds = 6000.0;
        $baseTime = 0.0; // Signal peaks at t=0 (since cos(0) = 1)

        // Generate dense, clean samples so phase estimation is sharp.
        $times = [];
        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $t = $baseTime + $i * 120.0 + mt_rand(0, 30); // ~2-min spacing, slightly jittered
            $times[] = (float) $t;
            $values[] = 5.0 + 3.0 * cos(2.0 * M_PI * $t / $periodSeconds);
        }

        $result = $this->periodogram->analyse($times, $values, 5000.0, 7000.0, 100);

        // The periodogram returns phase φ such that peak occurs when ωt + φ = 0.
        // Since our signal peaks at t=0 (relative to data start), φ should be ≈ 0.
        $omega = 2.0 * M_PI / $result->dominantPeriodSeconds;
        $predictedPeakOffset = -$result->phase / $omega;

        // Wrap into [0, period)
        $predictedPeakOffset = fmod($predictedPeakOffset, $result->dominantPeriodSeconds);
        if ($predictedPeakOffset < 0) {
            $predictedPeakOffset += $result->dominantPeriodSeconds;
        }

        // Peak should be near t=0 or near t=period (both are "at the start")
        $distanceFromZero = min($predictedPeakOffset, $result->dominantPeriodSeconds - $predictedPeakOffset);
        $this->assertLessThan(
            $periodSeconds / 20.0,
            $distanceFromZero,
            "Predicted peak offset is {$predictedPeakOffset}s but should be near 0",
        );
    }

    public function test_empty_input_returns_null_safely(): void
    {
        $result = $this->periodogram->analyse([], [], 3600.0, 10800.0, 50);

        $this->assertSame(0.0, $result->power);
        $this->assertSame(0.0, $result->amplitude);
        $this->assertEmpty($result->spectrum);
    }

    public function test_constant_values_returns_zero_power(): void
    {
        $times = [];
        $values = [];
        for ($i = 0; $i < 30; $i++) {
            $times[] = $i * 1000.0;
            $values[] = 5.0; // constant — no periodicity
        }

        $result = $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 50);

        $this->assertSame(0.0, $result->power);
        $this->assertSame(0.0, $result->amplitude);
    }

    public function test_spectrum_contains_one_entry_per_trial_frequency(): void
    {
        $times = [0.0, 1000.0, 3000.0, 7000.0, 12000.0];
        $values = [3.0, 6.0, 4.0, 8.0, 5.0];

        $result = $this->periodogram->analyse($times, $values, 3600.0, 10800.0, 25);

        $this->assertCount(25, $result->spectrum);
        foreach ($result->spectrum as $entry) {
            $this->assertArrayHasKey('period', $entry);
            $this->assertArrayHasKey('power', $entry);
            $this->assertGreaterThanOrEqual(0.0, $entry['power']);
        }
    }
}
