<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class Item extends Model
{
    /** @use HasFactory<\Database\Factories\ItemFactory> */
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'assignee_id',
        'project_id',
        'title',
        'description',
        'assignee_notes',
        'status',
        'position',
        'cognitive_load',
        'scheduled_date',
        'due_date',
        'completed_at',
        'recurrence_rule',
        'recurrence_strategy',
        'recurrence_parent_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'cognitive_load' => 'integer',
        'scheduled_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    #[\Override]
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    #[\Override]
    protected static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user this item is assigned to.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /**
     * Get the cognitive events logged against this item.
     */
    public function cognitiveEvents(): HasMany
    {
        return $this->hasMany(CognitiveEvent::class);
    }

    /**
     * Get the parent recurring item (template).
     */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'recurrence_parent_id');
    }

    /**
     * Get the recurring instances generated from this template.
     */
    public function recurrenceInstances(): HasMany
    {
        return $this->hasMany(Item::class, 'recurrence_parent_id');
    }

    /**
     * Check if this item is a recurring template.
     */
    public function isRecurringTemplate(): bool
    {
        return $this->recurrence_rule !== null && $this->recurrence_parent_id === null;
    }

    /**
     * Check if this item is an instance of a recurring item.
     */
    public function isRecurringInstance(): bool
    {
        return $this->recurrence_parent_id !== null;
    }

    /**
     * Check if this item is assigned to someone other than the owner.
     */
    public function isAssigned(): bool
    {
        return $this->assignee_id !== null;
    }

    /**
     * Check if this item is delegated (has an assignee and user is the owner).
     */
    public function isDelegated(?User $user = null): bool
    {
        $userId = $user?->id ?? $this->user_id;

        // Cast to string for reliable UUID comparison
        return $this->assignee_id !== null && (string) $this->user_id === (string) $userId;
    }

    /**
     * Scope to only return active items (not scheduled for the future).
     * Items with no scheduled_date or scheduled_date <= today are included.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('scheduled_date')
                ->orWhereDate('scheduled_date', '<=', now());
        });
    }

    /**
     * Scope to only return items scheduled in the future.
     */
    public function scopePlanned(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '>', now());
    }

    /**
     * Scope to return items that are overdue (due_date is in the past, not completed).
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->whereNotIn('status', ['done', 'wontdo']);
    }
}
