<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ChronobiologyServiceInterface;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the spectral analysis pipeline in the background after a task
 * completion. Debounced via ShouldBeUnique so a burst of completions
 * only triggers a single recalibration per 5-minute window.
 */
final class RecalibrateCognitivePhaseJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ChronobiologyServiceInterface $service): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            $service->computeProfile($user);
        }
    }

    /**
     * The unique ID of the job — one per user.
     */
    public function uniqueId(): string
    {
        return $this->userId;
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public function uniqueFor(): int
    {
        return 300;
    }
}
