<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CognitiveEventType;
use App\Jobs\RecalibrateCognitivePhaseJob;
use App\Models\CognitiveEvent;
use App\Models\Item;
use Carbon\CarbonImmutable;

/**
 * Silently logs cognitive events whenever an item transitions state.
 *
 * By reacting only to `isDirty('status')` we guarantee standard CRUD
 * (title edits, description changes, reordering, etc.) remains entirely
 * untouched — the acceptance criterion.
 */
final class ItemObserver
{
    /**
     * Handle the Item "created" event.
     */
    public function created(Item $item): void
    {
        // Creating a 'todo' is putting something on the shelf — no cognitive load applied yet.
        if ($item->status === 'doing') {
            $this->log($item, CognitiveEventType::Started);
        }

        if ($item->status === 'done') {
            $this->log($item, CognitiveEventType::Completed);
        }
    }

    /**
     * Handle the Item "updated" event.
     */
    public function updated(Item $item): void
    {
        if (! $item->isDirty('status')) {
            return;
        }

        $newStatus = $item->status;

        if ($newStatus === 'doing') {
            $this->log($item, CognitiveEventType::Started);
        }

        if ($newStatus === 'done') {
            $this->log($item, CognitiveEventType::Completed);
            RecalibrateCognitivePhaseJob::dispatch((string) $item->user_id);
        }

        // Reverting to 'todo' or 'wontdo' = returning to shelf, no event logged.
    }

    private function log(Item $item, CognitiveEventType $type): void
    {
        CognitiveEvent::create([
            'user_id' => $item->user_id,
            'item_id' => $item->id,
            'event_type' => $type->value,
            'cognitive_load_score' => $item->cognitive_load ?? 5,
            // Carbon's now() carries microsecond precision on PHP 8.4.
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
