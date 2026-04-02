<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\NotificationServiceInterface;
use App\Events\ModelChanged;
use App\Listeners\AuditAuthEvents;
use App\Listeners\AuditModelChanges;
use App\Mcp\Services\McpAuthContext;
use App\Mcp\Services\SchemaReflector;
use App\Services\NotificationService;
use App\Services\WebPushService;
use App\Services\WebPushServiceInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
        $this->app->bind(WebPushServiceInterface::class, WebPushService::class);

        // MCP services are scoped to the request lifecycle.
        $this->app->scoped(McpAuthContext::class);
        $this->app->singleton(SchemaReflector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Event::listen(ModelChanged::class, AuditModelChanges::class);
        Event::subscribe(AuditAuthEvents::class);
    }

    /**
     * Configure rate limiters for API endpoints.
     */
    private function configureRateLimiting(): void
    {
        // Strict rate limiting for authentication endpoints (by IP)
        // 5 attempts per minute - prevents brute force and mass account creation
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Moderate rate limiting for user discovery/lookup (by authenticated user)
        // 30 attempts per minute - prevents enumeration attacks
        RateLimiter::for('user-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiting for connection requests (by authenticated user)
        // 10 attempts per minute - prevents spam connection requests
        RateLimiter::for('connections', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // General API rate limiting (by authenticated user or IP)
        // 60 requests per minute for normal operations
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // MCP rate limiting (by authenticated user)
        // Higher limit for AI agents: 120 requests per minute
        // Each tool call or resource read counts as one request
        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
