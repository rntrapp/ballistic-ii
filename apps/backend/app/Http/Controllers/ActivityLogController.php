<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogItemResource;
use App\Models\AuditLog;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class ActivityLogController extends Controller
{
    /**
     * Get cursor-paginated item history for the authenticated user.
     *
     * Only completed/cancelled items are included.
     * Items are ordered by completion/cancellation time descending.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 50);
        $userId = (string) Auth::id();

        $activityTimestampExpression = "CASE WHEN status = 'wontdo' THEN COALESCE(completed_at, updated_at) ELSE completed_at END";

        $items = Item::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('assignee_id', $userId);
            })
            ->whereIn('status', ['done', 'wontdo'])
            ->with(['project', 'assignee', 'user'])
            ->select('items.*')
            ->selectRaw("{$activityTimestampExpression} as activity_sort_at")
            ->orderByDesc('activity_sort_at')
            ->orderByDesc('updated_at')
            ->cursorPaginate($perPage);

        $itemCollection = collect($items->items());
        $activityMetadata = $this->buildActivityMetadata($itemCollection);

        $data = $itemCollection
            ->map(function (Item $item) use ($activityMetadata, $request, $userId): array {
                $metadata = $activityMetadata[$item->id] ?? [];

                $fallbackActivityAt = $item->status === 'wontdo'
                    ? ($item->completed_at?->toIso8601String() ?? $item->updated_at?->toIso8601String())
                    : $item->completed_at?->toIso8601String();

                $item->setAttribute(
                    'activity_at',
                    $fallbackActivityAt ?? $item->updated_at?->toIso8601String()
                );
                $item->setAttribute('completed_by', $metadata['completed_by'] ?? null);
                $item->setAttribute(
                    'is_assigned_to_me',
                    $item->assignee_id !== null
                        && (string) $item->assignee_id === $userId
                        && (string) $item->user_id !== $userId
                );
                $item->setAttribute(
                    'is_delegated',
                    $item->assignee_id !== null
                        && (string) $item->user_id === $userId
                );

                return (new ActivityLogItemResource($item))->toArray($request);
            })
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'path' => $items->path(),
                'per_page' => $items->perPage(),
                'next_cursor' => $items->nextCursor()?->encode(),
                'prev_cursor' => $items->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array<string, array{activity_at?: string, completed_by?: array{id: string|null, name: string|null}}>
     */
    private function buildActivityMetadata(Collection $items): array
    {
        $itemIds = $items->pluck('id')->all();

        if ($itemIds === []) {
            return [];
        }

        /** @var Collection<string, Item> $itemsById */
        $itemsById = $items->keyBy('id');

        $logs = AuditLog::query()
            ->where('resource_type', 'item')
            ->whereIn('resource_id', $itemIds)
            ->whereIn('action', ['item_created', 'item_updated'])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();

        $metadata = [];

        foreach ($logs as $log) {
            $item = $itemsById->get($log->resource_id);
            if ($item === null || array_key_exists($log->resource_id, $metadata)) {
                continue;
            }

            $status = $this->extractLoggedStatus($log);
            if ($status === null || $status !== $item->status) {
                continue;
            }

            $metadata[$log->resource_id] = [
                'activity_at' => $log->created_at?->toIso8601String(),
                'completed_by' => [
                    'id' => $log->user?->id !== null ? (string) $log->user->id : null,
                    'name' => $log->user?->name,
                ],
            ];
        }

        return $metadata;
    }

    private function extractLoggedStatus(AuditLog $log): ?string
    {
        $afterStatus = data_get($log->metadata, 'after.status');
        if (is_string($afterStatus)) {
            return $afterStatus;
        }

        $createdStatus = data_get($log->metadata, 'status');
        if (is_string($createdStatus)) {
            return $createdStatus;
        }

        return null;
    }
}
