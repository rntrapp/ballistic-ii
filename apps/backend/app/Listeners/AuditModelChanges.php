<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ModelChanged;
use App\Jobs\WriteAuditLog;

final class AuditModelChanges
{
    public function handle(ModelChanged $event): void
    {
        $metadata = $event->metadata;

        if (! empty($event->before)) {
            $metadata['before'] = $event->before;
        }

        if (! empty($event->after)) {
            $metadata['after'] = $event->after;
        }

        WriteAuditLog::dispatch(
            $event->action,
            $event->resourceType,
            $event->resourceId,
            $event->userId,
            $event->ipAddress,
            $event->userAgent,
            $metadata,
        );
    }
}
