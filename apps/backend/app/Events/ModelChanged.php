<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ModelChanged
{
    use Dispatchable;

    /**
     * All properties are primitives so the event serialises safely for queued listeners
     * (no model re-fetching required).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?string $userId = null,
        public readonly array $before = [],
        public readonly array $after = [],
        public readonly array $metadata = [],
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}
}
