<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ChronobiologyServiceInterface;
use App\Enums\CognitiveEventType;
use App\Http\Resources\CognitivePhaseResource;
use App\Models\CognitiveEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class CognitivePhaseController extends Controller
{
    /**
     * Return the current user's live cognitive-phase snapshot.
     *
     * Reads the cached CognitiveProfile (or recomputes if stale) and
     * projects the current phase angle, next peak ETA, and confidence.
     */
    public function show(ChronobiologyServiceInterface $service): JsonResponse
    {
        $snapshot = $service->getCurrentPhase(Auth::user());

        if ($snapshot === null) {
            return response()->json([
                'has_profile' => false,
                'message' => 'Insufficient data. Complete at least 10 tasks to build your cognitive profile.',
            ]);
        }

        return (new CognitivePhaseResource($snapshot))->response();
    }

    /**
     * Return today's completed-event points for plotting on the wave.
     *
     * Only 'completed' events are surfaced (they're the meaningful "I
     * finished a unit of effort" signals). Scoped to the authenticated
     * user and to events occurring today.
     */
    public function events(): JsonResponse
    {
        $events = CognitiveEvent::query()
            ->where('user_id', Auth::id())
            ->where('event_type', CognitiveEventType::Completed->value)
            ->where('occurred_at', '>=', today())
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'cognitive_load_score', 'item_id'])
            ->map(fn (CognitiveEvent $e): array => [
                'occurred_at' => $e->occurred_at->format('Y-m-d\TH:i:s.uP'),
                'cognitive_load_score' => $e->cognitive_load_score,
                'item_id' => $e->item_id,
            ]);

        return response()->json(['data' => $events]);
    }
}
