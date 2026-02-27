<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RecalibrateCognitivePhaseJob;
use App\Models\CognitiveEvent;
use App\Models\Item;

final class ItemObserver
{
    /**
     * Handle the Item "updating" event.
     *
     * Logs cognitive events when an item's status transitions.
     * Uses the "updating" event so getOriginal() returns the pre-save values.
     */
    public function updating(Item $item): void
    {
        if (! $item->isDirty('status')) {
            return;
        }

        $oldStatus = $item->getOriginal('status');
        $newStatus = $item->status;

        $eventType = $this->resolveEventType($oldStatus, $newStatus);

        if ($eventType === null) {
            return;
        }

        $userId = $item->user_id;

        if ($userId === null) {
            return;
        }

        $score = $item->cognitive_load_score ?? $this->estimateCognitiveLoad($item);

        CognitiveEvent::create([
            'user_id' => $userId,
            'item_id' => $item->id,
            'event_type' => $eventType,
            'cognitive_load_score' => $score,
            'recorded_at' => now(),
        ]);

        if ($eventType === 'completed') {
            RecalibrateCognitivePhaseJob::dispatch($userId);
        }
    }

    /**
     * Determine the cognitive event type from a status transition.
     */
    private function resolveEventType(?string $oldStatus, string $newStatus): ?string
    {
        if ($newStatus === 'done') {
            return 'completed';
        }

        if ($newStatus === 'wontdo') {
            return 'cancelled';
        }

        if ($oldStatus === 'todo' && $newStatus === 'doing') {
            return 'started';
        }

        return null;
    }

    /**
     * Heuristic estimate of cognitive load when the user hasn't set one.
     *
     * Score 1-10 based on description length, whether a due date exists,
     * and whether the item belongs to a project.
     */
    private function estimateCognitiveLoad(Item $item): int
    {
        $score = 3; // baseline

        $descriptionLength = mb_strlen($item->description ?? '');
        if ($descriptionLength > 500) {
            $score += 3;
        } elseif ($descriptionLength > 100) {
            $score += 2;
        } elseif ($descriptionLength > 0) {
            $score += 1;
        }

        if ($item->due_date !== null) {
            $score += 1;
        }

        if ($item->project_id !== null) {
            $score += 1;
        }

        return min($score, 10);
    }
}
