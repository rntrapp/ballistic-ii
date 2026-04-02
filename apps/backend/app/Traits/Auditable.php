<?php

declare(strict_types=1);

namespace App\Traits;

use App\Events\ModelChanged;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Attributes that must never appear in audit log metadata.
     *
     * @var list<string>
     */
    private static array $redactedFields = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            $userId = self::resolveAuditUserId($model);
            $resourceType = strtolower(class_basename($model));

            ModelChanged::dispatch(
                "{$resourceType}_created",
                $resourceType,
                (string) $model->getKey(),
                $userId,
                [],
                self::redactAttributes($model->getAttributes()),
                [],
                request()?->ip(),
                request()?->userAgent(),
            );
        });

        static::updated(function ($model): void {
            $userId = self::resolveAuditUserId($model);
            $resourceType = strtolower(class_basename($model));
            $changes = $model->getChanges();

            // Don't log if nothing meaningful changed (e.g. only timestamps)
            $meaningfulChanges = array_diff_key($changes, array_flip(['updated_at', 'created_at']));
            if (empty($meaningfulChanges)) {
                return;
            }

            $before = [];
            $after = [];
            foreach ($meaningfulChanges as $key => $newValue) {
                if (in_array($key, self::$redactedFields, true)) {
                    continue;
                }
                $before[$key] = $model->getOriginal($key);
                $after[$key] = $newValue;
            }

            if (empty($after)) {
                return;
            }

            ModelChanged::dispatch(
                "{$resourceType}_updated",
                $resourceType,
                (string) $model->getKey(),
                $userId,
                $before,
                $after,
                [],
                request()?->ip(),
                request()?->userAgent(),
            );
        });

        static::deleted(function ($model): void {
            $userId = self::resolveAuditUserId($model);
            $resourceType = strtolower(class_basename($model));

            ModelChanged::dispatch(
                "{$resourceType}_deleted",
                $resourceType,
                (string) $model->getKey(),
                $userId,
                self::redactAttributes($model->getAttributes()),
                [],
                [],
                request()?->ip(),
                request()?->userAgent(),
            );
        });
    }

    /**
     * Remove sensitive fields from an attribute array before audit logging.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function redactAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::$redactedFields));
    }

    private static function resolveAuditUserId(mixed $model): ?string
    {
        // Use the authenticated user if available
        $authUser = Auth::user();
        if ($authUser) {
            return (string) $authUser->getAuthIdentifier();
        }

        // Fall back to user_id on the model itself (e.g. item created by a user)
        if (isset($model->user_id)) {
            return (string) $model->user_id;
        }

        return null;
    }
}
