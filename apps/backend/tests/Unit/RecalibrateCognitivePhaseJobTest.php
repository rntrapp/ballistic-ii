<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ChronobiologyServiceInterface;
use App\Jobs\RecalibrateCognitivePhaseJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecalibrateCognitivePhaseJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_compute_profile(): void
    {
        $user = User::factory()->create();

        $mockService = $this->createMock(ChronobiologyServiceInterface::class);
        $mockService->expects($this->once())
            ->method('computeProfile')
            ->with($this->callback(fn (User $u): bool => (string) $u->id === (string) $user->id));

        $this->app->instance(ChronobiologyServiceInterface::class, $mockService);

        $job = new RecalibrateCognitivePhaseJob((string) $user->id);
        app()->call([$job, 'handle']);
    }

    public function test_job_is_unique_per_user(): void
    {
        $job1 = new RecalibrateCognitivePhaseJob('user-abc');
        $job2 = new RecalibrateCognitivePhaseJob('user-xyz');

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job1);
        $this->assertSame('user-abc', $job1->uniqueId());
        $this->assertSame('user-xyz', $job2->uniqueId());
        $this->assertNotSame($job1->uniqueId(), $job2->uniqueId());
        $this->assertSame(300, $job1->uniqueFor());
    }

    public function test_job_handles_missing_user_gracefully(): void
    {
        $mockService = $this->createMock(ChronobiologyServiceInterface::class);
        $mockService->expects($this->never())->method('computeProfile');

        $this->app->instance(ChronobiologyServiceInterface::class, $mockService);

        $job = new RecalibrateCognitivePhaseJob('non-existent-uuid');

        // Should not throw
        app()->call([$job, 'handle']);
        $this->assertTrue(true);
    }

    public function test_job_implements_should_queue(): void
    {
        $job = new RecalibrateCognitivePhaseJob('user-id');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
        $this->assertSame(2, $job->tries);
    }
}
