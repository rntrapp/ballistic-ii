<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PeriodogramInterface;
use App\Enums\CognitivePhase;
use App\Models\CognitiveEvent;
use App\Models\CognitiveProfile;
use App\Models\User;
use App\Services\ChronobiologyService;
use App\Services\SpectralResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChronobiologyServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * With ≥10 events the service should invoke the periodogram and
     * persist a CognitiveProfile row.
     */
    public function test_compute_profile_with_sufficient_events_creates_record(): void
    {
        $user = User::factory()->create();
        CognitiveEvent::factory()->count(15)->create(['user_id' => $user->id]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $mockPeriodogram->expects($this->once())
            ->method('analyse')
            ->willReturn(new SpectralResult(
                dominantPeriodSeconds: 6000.0,
                power: 0.75,
                phase: 0.5,
                amplitude: 2.3,
                spectrum: [],
            ));

        $service = new ChronobiologyService($mockPeriodogram);
        $profile = $service->computeProfile($user);

        $this->assertNotNull($profile);
        $this->assertEquals(6000.0, $profile->dominant_period_seconds);
        $this->assertEquals(0.75, $profile->confidence);
        $this->assertEquals(15, $profile->sample_count);
        $this->assertDatabaseHas('cognitive_profiles', [
            'user_id' => $user->id,
            'sample_count' => 15,
        ]);
    }

    public function test_compute_profile_with_insufficient_events_returns_null(): void
    {
        $user = User::factory()->create();
        CognitiveEvent::factory()->count(5)->create(['user_id' => $user->id]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $mockPeriodogram->expects($this->never())->method('analyse');

        $service = new ChronobiologyService($mockPeriodogram);
        $profile = $service->computeProfile($user);

        $this->assertNull($profile);
        $this->assertDatabaseMissing('cognitive_profiles', ['user_id' => $user->id]);
    }

    public function test_compute_profile_uses_14_day_lookback_only(): void
    {
        $user = User::factory()->create();

        // 5 events within the 14-day window
        CognitiveEvent::factory()->count(5)->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now()->subDays(3),
        ]);

        // 20 events outside the window — should be ignored
        CognitiveEvent::factory()->count(20)->create([
            'user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        // Only 5 in-window events, which is < MIN_SAMPLES, so analyse should not fire
        $mockPeriodogram->expects($this->never())->method('analyse');

        $service = new ChronobiologyService($mockPeriodogram);
        $profile = $service->computeProfile($user);

        $this->assertNull($profile);
    }

    public function test_get_current_phase_reads_cached_profile(): void
    {
        $user = User::factory()->create();
        CognitiveProfile::factory()->peakedNow()->create(['user_id' => $user->id]);

        // Periodogram must NOT be invoked — we have a fresh profile
        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $mockPeriodogram->expects($this->never())->method('analyse');

        $service = new ChronobiologyService($mockPeriodogram);
        $snapshot = $service->getCurrentPhase($user);

        $this->assertNotNull($snapshot);
        $this->assertSame(CognitivePhase::Peak, $snapshot->phase);
    }

    public function test_get_current_phase_recomputes_when_stale(): void
    {
        $user = User::factory()->create();
        CognitiveProfile::factory()->stale()->create(['user_id' => $user->id]);
        CognitiveEvent::factory()->count(15)->create(['user_id' => $user->id]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $mockPeriodogram->expects($this->once())
            ->method('analyse')
            ->willReturn(new SpectralResult(5400.0, 0.6, 0.0, 2.0, []));

        $service = new ChronobiologyService($mockPeriodogram);
        $snapshot = $service->getCurrentPhase($user);

        $this->assertNotNull($snapshot);
        $this->assertEqualsWithDelta(90.0, $snapshot->dominantPeriodMinutes, 0.1);
    }

    public function test_project_phase_at_peak_returns_peak_enum(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $profile = CognitiveProfile::factory()->make([
            'dominant_period_seconds' => 6000.0,
            'phase_anchor_at' => $now,
            'confidence' => 0.8,
            'sample_count' => 42,
        ]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $service = new ChronobiologyService($mockPeriodogram);

        $snapshot = $service->projectPhaseAt($profile, $now);

        $this->assertSame(CognitivePhase::Peak, $snapshot->phase);
        $this->assertEqualsWithDelta(1.0, $snapshot->currentAmplitudeFraction, 0.01);

        CarbonImmutable::setTestNow();
    }

    public function test_project_phase_at_trough_returns_trough_enum(): void
    {
        $now = CarbonImmutable::now();
        $halfPeriodAgo = $now->subSeconds(3000); // Half of 6000s period → cos(π) = -1

        $profile = CognitiveProfile::factory()->make([
            'dominant_period_seconds' => 6000.0,
            'phase_anchor_at' => $halfPeriodAgo,
            'confidence' => 0.8,
            'sample_count' => 42,
        ]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $service = new ChronobiologyService($mockPeriodogram);

        $snapshot = $service->projectPhaseAt($profile, $now);

        $this->assertSame(CognitivePhase::Trough, $snapshot->phase);
        $this->assertLessThan(-0.9, $snapshot->currentAmplitudeFraction);
    }

    public function test_next_peak_is_after_now(): void
    {
        $now = CarbonImmutable::now();
        $profile = CognitiveProfile::factory()->make([
            'dominant_period_seconds' => 6000.0,
            'phase_anchor_at' => $now->subSeconds(1500), // Quarter cycle ago
            'confidence' => 0.7,
            'sample_count' => 30,
        ]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $service = new ChronobiologyService($mockPeriodogram);

        $snapshot = $service->projectPhaseAt($profile, $now);

        $this->assertTrue($snapshot->nextPeakAt->isAfter($now));
    }

    public function test_next_peak_within_one_period(): void
    {
        $now = CarbonImmutable::now();
        $profile = CognitiveProfile::factory()->make([
            'dominant_period_seconds' => 6000.0,
            'phase_anchor_at' => $now->subSeconds(2000),
            'confidence' => 0.7,
            'sample_count' => 30,
        ]);

        $mockPeriodogram = $this->createMock(PeriodogramInterface::class);
        $service = new ChronobiologyService($mockPeriodogram);

        $snapshot = $service->projectPhaseAt($profile, $now);

        $secondsUntilPeak = $snapshot->nextPeakAt->diffInSeconds($now, true);
        $this->assertLessThanOrEqual(6001.0, $secondsUntilPeak);
    }
}
