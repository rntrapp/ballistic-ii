<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\CognitivePhaseSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CognitivePhaseSnapshot $resource
 */
final class CognitivePhaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var CognitivePhaseSnapshot $snapshot */
        $snapshot = $this->resource;

        return [
            'has_profile' => true,
            'phase' => $snapshot->phase->value,
            'dominant_cycle_minutes' => round($snapshot->dominantPeriodMinutes, 2),
            'next_peak_at' => $snapshot->nextPeakAt->toIso8601String(),
            'confidence' => round($snapshot->confidence, 4),
            'amplitude_fraction' => round($snapshot->currentAmplitudeFraction, 4),
            'sample_count' => $snapshot->sampleCount,
        ];
    }
}
