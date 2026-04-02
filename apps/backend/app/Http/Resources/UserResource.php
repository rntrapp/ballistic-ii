<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'feature_flags' => array_merge(
                ['dates' => false, 'delegation' => false, 'ai_assistant' => false],
                $this->feature_flags ?? []
            ),
            'available_feature_flags' => array_merge(
                ['dates' => true, 'delegation' => true, 'ai_assistant' => true],
                AppSetting::globalFeatureFlags()
            ),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_admin' => $this->is_admin,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'favourites' => UserLookupResource::collection($this->whenLoaded('favourites')),
            'items_count' => $this->when($this->items_count !== null, $this->items_count),
            'projects_count' => $this->when($this->projects_count !== null, $this->projects_count),
            'tags_count' => $this->when($this->tags_count !== null, $this->tags_count),
        ];
    }
}
