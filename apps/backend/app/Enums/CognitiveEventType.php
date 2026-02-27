<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Types of cognitive event the ItemObserver can emit. Every case here
 * MUST have a producer in ItemObserver — see the parity test in
 * CognitiveEventObserverTest. Add a case only when you've wired up the
 * status transition that writes it.
 */
enum CognitiveEventType: string
{
    case Started = 'started';
    case Completed = 'completed';
}
