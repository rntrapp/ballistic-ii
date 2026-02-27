<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'assignee_id' => $this->assignee_id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'assignee_notes' => $this->assignee_notes,
            'status' => $this->status,
            'position' => $this->position,
            'cognitive_load' => $this->cognitive_load,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'recurrence_rule' => $this->recurrence_rule,
            'recurrence_strategy' => $this->recurrence_strategy,
            'recurrence_parent_id' => $this->recurrence_parent_id,
            'is_recurring_template' => $this->isRecurringTemplate(),
            'is_recurring_instance' => $this->isRecurringInstance(),
            'is_assigned' => $this->isAssigned(),
            'is_delegated' => $this->isDelegated($request->user()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'recurrence_parent' => new ItemResource($this->whenLoaded('recurrenceParent')),
            'assignee' => new UserLookupResource($this->whenLoaded('assignee')),
            'owner' => new UserLookupResource($this->whenLoaded('user')),
        ];
    }
}
