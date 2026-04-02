<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ActivityLogItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'is_assigned' => $this->assignee_id !== null,
            'is_assigned_to_me' => (bool) ($this->is_assigned_to_me ?? false),
            'is_delegated' => (bool) ($this->is_delegated ?? false),
            'project' => $this->project !== null
                ? new ProjectResource($this->project)
                : null,
            'assignee' => $this->assignee !== null
                ? new UserLookupResource($this->assignee)
                : null,
            'owner' => $this->user !== null
                ? new UserLookupResource($this->user)
                : null,
            'completed_by' => $this->completed_by,
            'activity_at' => $this->activity_at,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
