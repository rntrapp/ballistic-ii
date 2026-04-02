<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

final class ItemPolicy
{
    /**
     * Fields that an assignee is allowed to update.
     * All other fields can only be updated by the owner.
     */
    public const ASSIGNEE_ALLOWED_FIELDS = ['status', 'description', 'assignee_notes'];

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Both owner and assignee can view the item.
     */
    public function view(User $user, Item $item): bool
    {
        return $this->isOwner($user, $item) || $this->isAssignee($user, $item);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * Both owner and assignee can update the item.
     * Assignees have limited update permissions (status and assignee_notes only),
     * which must be enforced at the controller level.
     */
    public function update(User $user, Item $item): bool
    {
        return $this->isOwner($user, $item) || $this->isAssignee($user, $item);
    }

    /**
     * Check if the user (as assignee) is allowed to update the given fields.
     *
     * Assignees may update status, description, and assignee_notes unconditionally.
     * They may also set assignee_id to null (self-unassignment / rejection),
     * but cannot reassign the task to another user.
     *
     * @param  array<string>  $fieldsBeingUpdated
     * @param  array<string, mixed>  $validatedData  The validated request data for conditional checks
     */
    public function canAssigneeUpdateFields(User $user, Item $item, array $fieldsBeingUpdated, array $validatedData = []): bool
    {
        // Owner can update any field
        if ($this->isOwner($user, $item)) {
            return true;
        }

        // Assignee can only update allowed fields
        if ($this->isAssignee($user, $item)) {
            foreach ($fieldsBeingUpdated as $field) {
                if (in_array($field, self::ASSIGNEE_ALLOWED_FIELDS, true)) {
                    continue;
                }

                // Allow assignee_id only when setting to null (self-unassignment)
                if ($field === 'assignee_id' && array_key_exists('assignee_id', $validatedData) && $validatedData['assignee_id'] === null) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Only owner can delete items.
     */
    public function delete(User $user, Item $item): bool
    {
        return $this->isOwner($user, $item);
    }

    /**
     * Determine whether the user can restore the model.
     *
     * Only owner can restore items.
     */
    public function restore(User $user, Item $item): bool
    {
        return $this->isOwner($user, $item);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Item $item): bool
    {
        return $this->isOwner($user, $item);
    }

    /**
     * Check if the user is the owner of the item.
     */
    private function isOwner(User $user, Item $item): bool
    {
        return (string) $user->id === (string) $item->user_id;
    }

    /**
     * Check if the user is the assignee of the item.
     */
    private function isAssignee(User $user, Item $item): bool
    {
        return $item->assignee_id !== null && (string) $user->id === (string) $item->assignee_id;
    }
}
