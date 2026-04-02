<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\NotificationServiceInterface;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Connection;
use App\Models\Item;
use App\Models\User;
use App\Policies\ItemPolicy;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ItemController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Filter modes:
     * - assigned_to_me: Items where current user is the assignee (excludes self-owned)
     * - delegated: Items owned by current user that have been assigned to someone else
     * - (default): Items owned by current user that are NOT assigned to anyone
     *
     * By default, completed/cancelled items are excluded. Use include_completed=true to include them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = Auth::id();

        // Auto-expire recurring instances whose scheduled_date has passed
        // and whose strategy is 'expires'. Only instances (not templates).
        Item::where('user_id', $userId)
            ->where('recurrence_strategy', 'expires')
            ->whereNotNull('recurrence_parent_id')
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->whereIn('status', ['todo', 'doing'])
            ->update(['status' => 'wontdo', 'updated_at' => now()]);

        // Determine query mode based on filter parameter
        if ($request->boolean('assigned_to_me')) {
            // Items assigned to the current user by others (not self-owned)
            $query = Item::where('assignee_id', $userId)
                ->where('user_id', '!=', $userId) // Exclude self-assigned items
                ->with(['project', 'tags', 'user', 'assignee'])
                ->orderBy('position');
        } elseif ($request->boolean('delegated')) {
            // Items owned by current user that are assigned to others (not self)
            $query = Item::where('user_id', $userId)
                ->whereNotNull('assignee_id')
                ->where('assignee_id', '!=', $userId) // Exclude self-assigned items
                ->with(['project', 'tags', 'assignee'])
                ->orderBy('position');
        } else {
            // Default: Items owned by current user that are not assigned
            $query = Item::where('user_id', $userId)
                ->whereNull('assignee_id')
                ->with(['project', 'tags'])
                ->orderBy('position');
        }

        // Exclude completed/cancelled items by default
        if (! $request->boolean('include_completed')) {
            $query->whereNotIn('status', ['done', 'wontdo']);
        }

        // Apply scheduling scope: hide future-scheduled items by default
        $scope = $request->input('scope', 'active');
        if (! in_array($scope, ['active', 'planned', 'all'], true)) {
            $scope = 'active';
        }

        if ($scope === 'planned') {
            $query->planned();
        } elseif ($scope === 'all') {
            // No scope filter — return everything
        } else {
            // Default: only show active items (no future scheduled_date)
            $query->active();
        }

        if ($request->boolean('no_project')) {
            $query->whereNull('project_id');
        } elseif ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Allow specific status filter (overrides the default exclusion)
        if ($request->has('status')) {
            // Remove the previous whereNotIn if a specific status is requested
            $query->where('status', $request->status);
        }

        if ($request->has('tag_id')) {
            $query->whereHas('tags', function ($q) use ($request) {
                $q->where('tags.id', $request->tag_id);
            });
        }

        // Date filtering
        if ($request->has('scheduled_date')) {
            $query->whereDate('scheduled_date', $request->scheduled_date);
        }

        if ($request->has('scheduled_from')) {
            $query->whereDate('scheduled_date', '>=', $request->scheduled_from);
        }

        if ($request->has('scheduled_to')) {
            $query->whereDate('scheduled_date', '<=', $request->scheduled_to);
        }

        if ($request->has('due_from')) {
            $query->whereDate('due_date', '>=', $request->due_from);
        }

        if ($request->has('due_to')) {
            $query->whereDate('due_date', '<=', $request->due_to);
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_date')
                ->whereDate('due_date', '<', now())
                ->whereNotIn('status', ['done', 'wontdo']);
        }

        $items = $query->get();

        return ItemResource::collection($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreItemRequest $request, NotificationServiceInterface $notificationService): JsonResponse
    {
        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        /** @var User $owner */
        $owner = Auth::user();

        // Ensure a connection exists with the assignee (auto-create if needed)
        if (! empty($validated['assignee_id'])) {
            $this->ensureConnection($owner, $validated['assignee_id']);
        }

        // Auto-set completed_at when an item is completed or cancelled.
        if (in_array($validated['status'] ?? null, ['done', 'wontdo'], true)) {
            $validated['completed_at'] = now();
        }

        $item = DB::transaction(function () use ($validated, $tagIds): Item {
            $item = Item::create([
                ...$validated,
                'user_id' => Auth::id(),
                'position' => $validated['position'] ?? 0,
            ]);

            if (! empty($tagIds)) {
                $item->tags()->sync($tagIds);
            }

            return $item;
        });

        // Create notification if item was created with an assignee
        if (! empty($validated['assignee_id'])) {
            $assignee = User::find($validated['assignee_id']);
            if ($assignee !== null) {
                $notificationService->notifyTaskAssignment(
                    $assignee,
                    (string) $item->id,
                    $item->title,
                    $owner->name
                );
            }
        }

        $item->load(['project', 'tags', 'assignee']);

        return (new ItemResource($item))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        $item->load(['project', 'tags', 'assignee']);

        return new ItemResource($item);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItemRequest $request, Item $item, NotificationServiceInterface $notificationService): JsonResponse|ItemResource
    {
        $this->authorize('update', $item);

        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? null;
        unset($validated['tag_ids']);

        /** @var User $currentUser */
        $currentUser = Auth::user();
        $isOwner = (string) $item->user_id === (string) $currentUser->id;

        // Enforce field restrictions for assignees (they can only update status, description, assignee_notes, and self-unassign)
        if (! $isOwner) {
            $fieldsBeingUpdated = array_keys($validated);
            $policy = new ItemPolicy;
            if (! $policy->canAssigneeUpdateFields($currentUser, $item, $fieldsBeingUpdated, $validated)) {
                return response()->json(
                    ['message' => 'Assignees can only update status, description, and notes.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

        // Track changes for notifications
        $previousAssigneeId = $item->assignee_id;
        $newAssigneeId = array_key_exists('assignee_id', $validated)
            ? $validated['assignee_id']
            : $previousAssigneeId;
        $previousStatus = $item->status;
        $newStatus = $validated['status'] ?? $previousStatus;
        $previousTitle = $item->title;
        $previousDescription = $item->description;
        $previousDueDate = $item->due_date?->toDateString();

        // Ensure a connection exists when owner assigns to a new user
        if ($isOwner && $newAssigneeId !== null && (string) $newAssigneeId !== (string) $previousAssigneeId) {
            $owner = User::find($item->user_id);
            $this->ensureConnection($owner, $newAssigneeId);
        }

        // Auto-manage completed_at based on status changes.
        if (isset($validated['status'])) {
            if (in_array($validated['status'], ['done', 'wontdo'], true)
                && $validated['status'] !== $item->status) {
                $validated['completed_at'] = now();
            } elseif (in_array($validated['status'], ['todo', 'doing'], true)
                && in_array($item->status, ['done', 'wontdo'], true)) {
                $validated['completed_at'] = null;
            }
        }

        DB::transaction(function () use ($item, $validated, $tagIds): void {
            $item->update($validated);

            if ($tagIds !== null) {
                $item->tags()->sync($tagIds);
            }
        });

        // Get owner for notifications
        $owner = User::find($item->user_id);

        // Notification: Task unassigned by owner
        if ($isOwner && $previousAssigneeId !== null && $newAssigneeId === null) {
            $previousAssignee = User::find($previousAssigneeId);
            if ($previousAssignee !== null) {
                $notificationService->notifyTaskUnassigned(
                    $previousAssignee,
                    (string) $item->id,
                    $item->title,
                    $owner->name
                );
            }
        }

        // Notification: Assignee rejected / unassigned themselves
        if (! $isOwner && $previousAssigneeId !== null && $newAssigneeId === null && $owner !== null) {
            $notificationService->notifyTaskRejected(
                $owner,
                (string) $item->id,
                $item->title,
                $currentUser->name
            );
        }

        // Notification: Task newly assigned
        if ($newAssigneeId !== null && (string) $newAssigneeId !== (string) $previousAssigneeId) {
            $assignee = User::find($newAssigneeId);
            if ($assignee !== null) {
                $notificationService->notifyTaskAssignment(
                    $assignee,
                    (string) $item->id,
                    $item->title,
                    $owner->name
                );
            }
        }

        // Notification: Task completed/cancelled by owner (notify assignee)
        if ($isOwner && $item->assignee_id !== null) {
            $assignee = User::find($item->assignee_id);
            if ($assignee !== null) {
                // Check if status changed to done/wontdo
                if (in_array($newStatus, ['done', 'wontdo']) && ! in_array($previousStatus, ['done', 'wontdo'])) {
                    $notificationService->notifyTaskCompleted(
                        $assignee,
                        (string) $item->id,
                        $item->title,
                        $owner->name,
                        $newStatus
                    );
                }

                // Check if title, description, or due date changed (and status didn't complete)
                $titleChanged = isset($validated['title']) && $validated['title'] !== $previousTitle;
                $descriptionChanged = isset($validated['description']) && $validated['description'] !== $previousDescription;
                $dueDateChanged = array_key_exists('due_date', $validated) && ($validated['due_date'] ?? null) !== $previousDueDate;

                if (($titleChanged || $descriptionChanged || $dueDateChanged) && ! in_array($newStatus, ['done', 'wontdo'])) {
                    $changes = [];
                    if ($titleChanged) {
                        $changes['title'] = ['from' => $previousTitle, 'to' => $item->title];
                    }
                    if ($descriptionChanged) {
                        $changes['description'] = true;
                    }
                    if ($dueDateChanged) {
                        $changes['due_date'] = ['from' => $previousDueDate, 'to' => $item->due_date?->toDateString()];
                    }

                    $notificationService->notifyTaskUpdated(
                        $assignee,
                        (string) $item->id,
                        $item->title,
                        $owner->name,
                        $changes
                    );
                }
            }
        }

        // Notification: Task completed/cancelled by assignee (notify owner)
        if (! $isOwner && $owner !== null) {
            if (in_array($newStatus, ['done', 'wontdo']) && ! in_array($previousStatus, ['done', 'wontdo'])) {
                $notificationService->notifyTaskCompletedByAssignee(
                    $owner,
                    (string) $item->id,
                    $item->title,
                    $currentUser->name,
                    $newStatus
                );
            }
        }

        $item->load(['project', 'tags', 'assignee']);

        return new ItemResource($item);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Item $item): JsonResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Reorder active items by updating their positions.
     *
     * Only the owner can reorder items (not assignees).
     * Reordering is limited to active items (`todo` and `doing`) so completed
     * and cancelled items keep their existing positions and timestamps.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.position' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $submittedIds = array_column($validated['items'], 'id');

        // Check if user is trying to reorder items they're assigned to but don't own
        $assignedNotOwned = Item::whereIn('id', $submittedIds)
            ->where('assignee_id', Auth::id())
            ->where('user_id', '!=', Auth::id())
            ->exists();

        if ($assignedNotOwned) {
            abort(403, 'Assignees cannot reorder items.');
        }

        DB::transaction(function () use ($validated, $submittedIds): void {
            // Collect only items owned by this user in a single query
            $ownedIds = Item::where('user_id', Auth::id())
                ->whereIn('status', ['todo', 'doing'])
                ->whereIn('id', $submittedIds)
                ->pluck('id')
                ->flip();
            $reorderedItemIds = $ownedIds->keys()->all();

            if ($reorderedItemIds === []) {
                return;
            }

            $maxPosition = -1;

            foreach ($validated['items'] as $itemData) {
                if ($ownedIds->has($itemData['id'])) {
                    Item::where('id', $itemData['id'])
                        ->update(['position' => $itemData['position']]);

                    if ($itemData['position'] > $maxPosition) {
                        $maxPosition = $itemData['position'];
                    }
                }
            }

            // Renumber only other active items to positions after the submitted range.
            // Completed/cancelled items are intentionally left untouched.
            $otherItemIds = Item::where('user_id', Auth::id())
                ->whereIn('status', ['todo', 'doing'])
                ->whereNotIn('id', $reorderedItemIds)
                ->orderBy('position')
                ->pluck('id');

            $nextPosition = $maxPosition + 1;

            foreach ($otherItemIds as $otherId) {
                Item::where('id', $otherId)
                    ->update(['position' => $nextPosition++]);
            }
        });

        return response()->json(['message' => 'Items reordered successfully']);
    }

    /**
     * Generate recurring instances for a recurring item template.
     */
    public function generateRecurrences(Request $request, Item $item, RecurrenceService $recurrenceService): AnonymousResourceCollection
    {
        $this->authorize('update', $item);

        if (! $item->isRecurringTemplate()) {
            abort(Response::HTTP_BAD_REQUEST, 'This item is not a recurring template.');
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $instances = $recurrenceService->generateInstances(
            $item,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date'])
        );

        foreach ($instances as $instance) {
            $instance->load(['project', 'tags']);
        }

        return ItemResource::collection(collect($instances));
    }

    /**
     * Ensure an accepted connection exists between two users.
     *
     * If no connection exists, creates one with status 'accepted'.
     * If a pending connection exists, accepts it.
     * If already connected, does nothing.
     */
    private function ensureConnection(User $owner, string $assigneeId): void
    {
        if ($owner->isConnectedWith($assigneeId)) {
            return;
        }

        DB::transaction(function () use ($owner, $assigneeId): void {
            // Check for any existing connection (either direction)
            $existing = Connection::where(function ($query) use ($owner, $assigneeId) {
                $query->where('requester_id', $owner->id)
                    ->where('addressee_id', $assigneeId);
            })->orWhere(function ($query) use ($owner, $assigneeId) {
                $query->where('requester_id', $assigneeId)
                    ->where('addressee_id', $owner->id);
            })->first();

            if ($existing !== null) {
                // Accept if pending, or delete and recreate if declined
                if ($existing->isPending()) {
                    $existing->accept();
                } elseif ($existing->status === 'declined') {
                    $existing->delete();
                    Connection::create([
                        'requester_id' => $owner->id,
                        'addressee_id' => $assigneeId,
                        'status' => 'accepted',
                    ]);
                }
            } else {
                // No connection at all — create an accepted one
                Connection::create([
                    'requester_id' => $owner->id,
                    'addressee_id' => $assigneeId,
                    'status' => 'accepted',
                ]);
            }
        });
    }
}
