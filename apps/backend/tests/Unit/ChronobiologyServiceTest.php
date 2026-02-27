<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CognitiveEvent;
use App\Models\User;
use App\Services\ChronobiologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ChronobiologyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChronobiologyService $service;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChronobiologyService;
    }

    public function test_known_sine_wave_recovers_correct_frequency(): void
    {
        $user = User::factory()->create();

        // Generate events following a 120-minute cycle
        $periodMinutes = 120.0;
        $baseTime = now()->subDays(10);

        for ($i = 0; $i < 100; $i++) {
            $minutes = $i * 15; // every 15 minutes
            $score = (int) round(5 + 4 * sin(2 * M_PI * $minutes / $periodMinutes));
            $score = max(1, min(10, $score));

            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => $score,
                'recorded_at' => $baseTime->copy()->addMinutes($minutes),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        // Should detect a cycle near 120 minutes (allow some tolerance)
        $this->assertGreaterThan(90, $result['dominant_cycle_minutes']);
        $this->assertLessThan(180, $result['dominant_cycle_minutes']);
        $this->assertGreaterThan(0.0, $result['confidence_score']);
    }

    public function test_periodic_completions_identifies_cycle(): void
    {
        $user = User::factory()->create();

        // Create events at 100-minute intervals with alternating scores
        $baseTime = now()->subDays(7);
        for ($i = 0; $i < 50; $i++) {
            $time = $baseTime->copy()->addMinutes($i * 50);
            $score = ($i % 2 === 0) ? 9 : 1;

            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => $score,
                'recorded_at' => $time,
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        $this->assertGreaterThan(0.0, $result['confidence_score']);
        $this->assertContains($result['current_phase'], ['Peak', 'Trough', 'Recovery']);
    }

    public function test_insufficient_data_returns_default(): void
    {
        $user = User::factory()->create();

        // Only create 2 events (below minimum of 4)
        CognitiveEvent::factory()->count(2)->completed()->create([
            'user_id' => $user->id,
        ]);

        $result = $this->service->analyse((string) $user->id);

        $this->assertEquals(90.0, $result['dominant_cycle_minutes']);
        $this->assertEquals(0.0, $result['confidence_score']);
        $this->assertEquals('Peak', $result['current_phase']);
    }

    public function test_constant_values_returns_default(): void
    {
        $user = User::factory()->create();

        // All events with the same score (zero variance)
        $baseTime = now()->subDays(5);
        for ($i = 0; $i < 20; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => 5,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 30),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        $this->assertEquals(0.0, $result['confidence_score']);
    }

    public function test_performance_5000_data_points_under_400ms(): void
    {
        // Build 5000 in-memory data points — no DB involved.
        $times = [];
        $values = [];

        for ($i = 0; $i < 5000; $i++) {
            $times[] = (float) ($i * 4);              // minutes
            $values[] = (float) rand(1, 10);
        }

        $mean = array_sum($values) / count($values);

        // Call the private lombScargle method via reflection to isolate the algorithm.
        $method = new \ReflectionMethod($this->service, 'lombScargle');

        $start = microtime(true);
        $result = $method->invoke($this->service, $times, $values, $mean);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertLessThan(400, $elapsed, "Lomb-Scargle took {$elapsed}ms, expected under 400ms");
        $this->assertNotNull($result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('frequency', $result);
        $this->assertArrayHasKey('confidence', $result);
    }

    public function test_decimation_preserves_frequency_detection(): void
    {
        $user = User::factory()->create();

        // Generate 2000 events (above MAX_PERIODOGRAM_POINTS of 1500) following a 120-min cycle.
        $periodMinutes = 120.0;
        $baseTime = now()->subDays(14);

        for ($i = 0; $i < 2000; $i++) {
            $minutes = $i * 10; // every 10 minutes
            $score = (int) round(5 + 4 * sin(2 * M_PI * $minutes / $periodMinutes));
            $score = max(1, min(10, $score));

            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => $score,
                'recorded_at' => $baseTime->copy()->addMinutes($minutes),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        // Decimated data should still recover the 120-minute cycle.
        $this->assertGreaterThan(90, $result['dominant_cycle_minutes']);
        $this->assertLessThan(180, $result['dominant_cycle_minutes']);
        $this->assertGreaterThan(0.0, $result['confidence_score']);
    }

    public function test_analyse_returns_complete_response_shape(): void
    {
        $user = User::factory()->create();

        // Enough events for a real analysis.
        $baseTime = now()->subDays(3);
        for ($i = 0; $i < 10; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => ($i % 2 === 0) ? 9 : 2,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 90),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        // All required keys must be present.
        $this->assertArrayHasKey('dominant_cycle_minutes', $result);
        $this->assertArrayHasKey('current_phase', $result);
        $this->assertArrayHasKey('phase_progress', $result);
        $this->assertArrayHasKey('next_peak_at', $result);
        $this->assertArrayHasKey('confidence_score', $result);
        $this->assertArrayHasKey('today_events', $result);

        // Type checks.
        $this->assertIsFloat($result['dominant_cycle_minutes']);
        $this->assertIsString($result['current_phase']);
        $this->assertIsFloat($result['phase_progress']);
        $this->assertIsString($result['next_peak_at']);
        $this->assertIsFloat($result['confidence_score']);
        $this->assertIsArray($result['today_events']);

        // Dominant cycle within the testable range (60–240 min).
        $this->assertGreaterThanOrEqual(60.0, $result['dominant_cycle_minutes']);
        $this->assertLessThanOrEqual(240.0, $result['dominant_cycle_minutes']);

        // Phase must be one of the three labels.
        $this->assertContains($result['current_phase'], ['Peak', 'Trough', 'Recovery']);
    }

    public function test_phase_progress_is_bounded_zero_to_one(): void
    {
        $user = User::factory()->create();

        $baseTime = now()->subDays(5);
        for ($i = 0; $i < 20; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => ($i % 2 === 0) ? 9 : 1,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 60),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        $this->assertGreaterThanOrEqual(0.0, $result['phase_progress']);
        $this->assertLessThanOrEqual(1.0, $result['phase_progress']);
    }

    public function test_next_peak_at_is_valid_future_iso8601(): void
    {
        $user = User::factory()->create();

        $baseTime = now()->subDays(5);
        for ($i = 0; $i < 20; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => ($i % 2 === 0) ? 9 : 1,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 60),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        // Must parse as a valid datetime.
        $nextPeak = Carbon::parse($result['next_peak_at']);
        $this->assertTrue($nextPeak->isFuture(), 'next_peak_at should be in the future');
    }

    public function test_next_peak_at_is_consistent_with_phase(): void
    {
        $user = User::factory()->create();

        // 100 events with a clear 120-minute sine wave — enough for confident detection.
        $periodMinutes = 120.0;
        $baseTime = now()->subDays(10);

        for ($i = 0; $i < 100; $i++) {
            $minutes = $i * 15;
            $score = (int) round(5 + 4 * sin(2 * M_PI * $minutes / $periodMinutes));
            $score = max(1, min(10, $score));

            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => $score,
                'recorded_at' => $baseTime->copy()->addMinutes($minutes),
            ]);
        }

        $result = $this->service->analyse((string) $user->id);

        // Ensure we got a real analysis (not the default).
        $this->assertGreaterThan(0.0, $result['confidence_score'], 'Expected confident detection');

        $cycleMins = $result['dominant_cycle_minutes'];
        $phaseProgress = $result['phase_progress'];

        // Compute actual minutes-to-peak from the returned next_peak_at.
        $nextPeak = Carbon::parse($result['next_peak_at']);
        $actualMinutes = (float) now()->diffInSeconds($nextPeak) / 60.0;

        // Peak entry is at 5/6 of the cycle (phase = 5π/3).
        // Time-to-peak must be derived from the phase, not raw elapsed time.
        $peakEntryFraction = 5.0 / 6.0;

        if ($phaseProgress < $peakEntryFraction) {
            $expectedMinutes = ($peakEntryFraction - $phaseProgress) * $cycleMins;
        } else {
            $expectedMinutes = (1.0 - $phaseProgress + $peakEntryFraction) * $cycleMins;
        }

        // Allow 2-minute tolerance for integer rounding in addMinutes().
        $this->assertEqualsWithDelta($expectedMinutes, $actualMinutes, 2.0,
            'next_peak_at should target the Peak entry at 5π/3, not phase 0. '
            ."Phase progress={$phaseProgress}, cycle={$cycleMins}min, "
            ."expected≈{$expectedMinutes}min, got≈{$actualMinutes}min"
        );
    }

    public function test_today_events_populated_when_events_exist_today(): void
    {
        $user = User::factory()->create();

        // Create several events in the past for analysis, plus events today.
        $baseTime = now()->subDays(5);
        for ($i = 0; $i < 10; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => 5,
                'recorded_at' => $baseTime->copy()->addMinutes($i * 60),
            ]);
        }

        // Create 2 events today.
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 8,
            'recorded_at' => now()->subHours(2),
        ]);
        CognitiveEvent::factory()->started()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 3,
            'recorded_at' => now()->subHour(),
        ]);

        $result = $this->service->analyse((string) $user->id);

        $this->assertCount(2, $result['today_events']);

        // Each today event must have the correct structure.
        foreach ($result['today_events'] as $event) {
            $this->assertArrayHasKey('recorded_at', $event);
            $this->assertArrayHasKey('cognitive_load_score', $event);
            $this->assertArrayHasKey('event_type', $event);
            // Verify it parses as today's date.
            $this->assertTrue(Carbon::parse($event['recorded_at'])->isToday());
        }
    }

    public function test_events_older_than_lookback_are_excluded(): void
    {
        $user = User::factory()->create();

        // Create events 20 days ago (beyond the 14-day lookback).
        for ($i = 0; $i < 10; $i++) {
            CognitiveEvent::factory()->completed()->create([
                'user_id' => $user->id,
                'cognitive_load_score' => ($i % 2 === 0) ? 9 : 1,
                'recorded_at' => now()->subDays(20)->addMinutes($i * 60),
            ]);
        }

        // Only 2 events within lookback (below MIN_EVENTS threshold of 4).
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 7,
            'recorded_at' => now()->subDays(2),
        ]);
        CognitiveEvent::factory()->completed()->create([
            'user_id' => $user->id,
            'cognitive_load_score' => 3,
            'recorded_at' => now()->subDay(),
        ]);

        $result = $this->service->analyse((string) $user->id);

        // Should return default because only 2 recent events qualify.
        $this->assertEquals(0.0, $result['confidence_score']);
        $this->assertEquals(90.0, $result['dominant_cycle_minutes']);
    }

    public function test_map_phase_boundary_conditions(): void
    {
        $method = new \ReflectionMethod($this->service, 'mapPhase');

        $piThird = M_PI / 3.0;

        // Region 1: 0 to π/3 → Peak
        $this->assertEquals('Peak', $method->invoke($this->service, 0.0));
        $this->assertEquals('Peak', $method->invoke($this->service, $piThird - 0.01));

        // Region 2: π/3 to 2π/3 → Recovery
        $this->assertEquals('Recovery', $method->invoke($this->service, $piThird));
        $this->assertEquals('Recovery', $method->invoke($this->service, 2.0 * $piThird - 0.01));

        // Region 3: 2π/3 to 4π/3 → Trough
        $this->assertEquals('Trough', $method->invoke($this->service, 2.0 * $piThird));
        $this->assertEquals('Trough', $method->invoke($this->service, 4.0 * $piThird - 0.01));

        // Region 4: 4π/3 to 5π/3 → Recovery
        $this->assertEquals('Recovery', $method->invoke($this->service, 4.0 * $piThird));
        $this->assertEquals('Recovery', $method->invoke($this->service, 5.0 * $piThird - 0.01));

        // Region 5: 5π/3 to 2π → Peak
        $this->assertEquals('Peak', $method->invoke($this->service, 5.0 * $piThird));
        $this->assertEquals('Peak', $method->invoke($this->service, 2.0 * M_PI - 0.01));
    }

    public function test_analyse_with_no_events_returns_default(): void
    {
        $user = User::factory()->create();

        $result = $this->service->analyse((string) $user->id);

        $this->assertEquals(90.0, $result['dominant_cycle_minutes']);
        $this->assertEquals('Peak', $result['current_phase']);
        $this->assertEquals(0.0, $result['phase_progress']);
        $this->assertEquals(0.0, $result['confidence_score']);
        $this->assertIsArray($result['today_events']);
        $this->assertEmpty($result['today_events']);
    }
}
