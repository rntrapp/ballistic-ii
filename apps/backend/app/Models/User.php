<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
        'feature_flags',
        'password',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'feature_flags' => 'array',
        ];
    }

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    #[\Override]
    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    #[\Override]
    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model): void {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Get items assigned to this user by other users.
     */
    public function assignedItems(): HasMany
    {
        return $this->hasMany(Item::class, 'assignee_id');
    }

    /**
     * Get this user's notifications.
     */
    public function taskNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get this user's unread notifications.
     */
    public function unreadNotifications(): HasMany
    {
        return $this->taskNotifications()->whereNull('read_at');
    }

    /**
     * Get connection requests sent by this user.
     */
    public function sentConnectionRequests(): HasMany
    {
        return $this->hasMany(Connection::class, 'requester_id');
    }

    /**
     * Get connection requests received by this user.
     */
    public function receivedConnectionRequests(): HasMany
    {
        return $this->hasMany(Connection::class, 'addressee_id');
    }

    /**
     * Get all accepted connections for this user (both directions).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function connections(): \Illuminate\Support\Collection
    {
        $sentIds = $this->sentConnectionRequests()
            ->where('status', 'accepted')
            ->pluck('addressee_id');

        $receivedIds = $this->receivedConnectionRequests()
            ->where('status', 'accepted')
            ->pluck('requester_id');

        $connectedIds = $sentIds->merge($receivedIds)->unique();

        return User::whereIn('id', $connectedIds)->get();
    }

    /**
     * Check if this user is connected with another user.
     */
    public function isConnectedWith(User|string $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Connection::where('status', 'accepted')
            ->where(function ($query) use ($userId) {
                $query->where(function ($q) use ($userId) {
                    $q->where('requester_id', $this->id)
                        ->where('addressee_id', $userId);
                })->orWhere(function ($q) use ($userId) {
                    $q->where('requester_id', $userId)
                        ->where('addressee_id', $this->id);
                });
            })
            ->exists();
    }

    /**
     * Get this user's push notification subscriptions.
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Get this user's audit logs.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get this user's favourite contacts.
     */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_favourites', 'user_id', 'favourite_id')
            ->orderByPivot('created_at', 'desc');
    }

    /**
     * Get this user's cognitive events (task-state transitions used for rhythm analysis).
     */
    public function cognitiveEvents(): HasMany
    {
        return $this->hasMany(CognitiveEvent::class);
    }

    /**
     * Get this user's cached cognitive profile (spectral analysis result).
     */
    public function cognitiveProfile(): HasOne
    {
        return $this->hasOne(CognitiveProfile::class);
    }
}
