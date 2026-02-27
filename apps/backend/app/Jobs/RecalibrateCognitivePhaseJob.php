<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ChronobiologyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecalibrateCognitivePhaseJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ChronobiologyService $service): void
    {
        $result = $service->analyse($this->userId);

        Cache::put(
            "cognitive_phase:{$this->userId}",
            $result,
            now()->addMinutes(5),
        );
    }
}
