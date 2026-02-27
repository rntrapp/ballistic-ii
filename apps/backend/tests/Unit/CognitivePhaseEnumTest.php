<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CognitivePhase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CognitivePhaseEnumTest extends TestCase
{
    public function test_phase_angle_near_zero_is_peak(): void
    {
        $this->assertSame(CognitivePhase::Peak, CognitivePhase::fromPhaseAngle(0.0));
        $this->assertSame(CognitivePhase::Peak, CognitivePhase::fromPhaseAngle(0.1));
    }

    public function test_phase_angle_near_pi_is_trough(): void
    {
        $this->assertSame(CognitivePhase::Trough, CognitivePhase::fromPhaseAngle(M_PI));
    }

    public function test_phase_angle_at_pi_half_is_recovery(): void
    {
        $this->assertSame(CognitivePhase::Recovery, CognitivePhase::fromPhaseAngle(M_PI / 2));
    }

    public function test_negative_angle_normalised(): void
    {
        // -0.1 rad → should wrap to near 2π → peak
        $this->assertSame(CognitivePhase::Peak, CognitivePhase::fromPhaseAngle(-0.1));
    }

    public function test_angle_over_two_pi_normalised(): void
    {
        // 2π + π → should normalise to π → trough
        $this->assertSame(CognitivePhase::Trough, CognitivePhase::fromPhaseAngle(3.0 * M_PI));
    }

    /**
     * Table-driven: sweep 24 equally-spaced angles across [0, 2π)
     * and assert the expected phase classification.
     *
     * cos(θ) ≥ 0.5 → θ ∈ [0, π/3) ∪ (5π/3, 2π]   → Peak
     * cos(θ) ≤ -0.5 → θ ∈ (2π/3, 4π/3)           → Trough
     * otherwise → Recovery
     */
    #[DataProvider('angleProvider')]
    public function test_phase_classification_sweep(float $angle, CognitivePhase $expected): void
    {
        $this->assertSame(
            $expected,
            CognitivePhase::fromPhaseAngle($angle),
            "Angle {$angle} rad (cos=".round(cos($angle), 3).") should be {$expected->value}",
        );
    }

    /**
     * @return list<array{float, CognitivePhase}>
     */
    public static function angleProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 24; $i++) {
            $angle = 2.0 * M_PI * $i / 24.0;
            $cosine = cos($angle);

            $expected = match (true) {
                $cosine >= 0.5 => CognitivePhase::Peak,
                $cosine <= -0.5 => CognitivePhase::Trough,
                default => CognitivePhase::Recovery,
            };

            $cases[] = [$angle, $expected];
        }

        return $cases;
    }
}
