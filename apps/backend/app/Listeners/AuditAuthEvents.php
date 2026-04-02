<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;

final class AuditAuthEvents
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'auth_login',
            'resource_type' => 'user',
            'resource_id' => (string) $event->user->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'status' => 'success',
            'metadata' => [
                'guard' => $event->guard,
            ],
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'auth_logout',
            'resource_type' => 'user',
            'resource_id' => (string) $event->user->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'status' => 'success',
            'metadata' => [
                'guard' => $event->guard,
            ],
        ]);
    }

    public function handleRegistered(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'auth_registered',
            'resource_type' => 'user',
            'resource_id' => (string) $event->user->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'status' => 'success',
            'metadata' => [
                'email' => $event->user->email,
            ],
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => 'auth_failed',
            'resource_type' => 'user',
            'resource_id' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'status' => 'failed',
            'metadata' => [
                'guard' => $event->guard,
                'email' => $event->credentials['email'] ?? null,
            ],
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Registered::class => 'handleRegistered',
            Failed::class => 'handleFailed',
        ];
    }
}
