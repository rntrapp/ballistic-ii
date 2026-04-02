<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WriteAuditLog implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly array $metadata = [],
    ) {
        $this->onQueue('audit');
    }

    public function handle(): void
    {
        AuditLog::create([
            'user_id' => $this->userId,
            'action' => $this->action,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'status' => 'success',
            'metadata' => $this->metadata,
        ]);
    }
}
