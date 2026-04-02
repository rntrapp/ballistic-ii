<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FavouriteController;
use App\Http\Controllers\Api\McpTokenController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserDiscoveryController;
use App\Http\Controllers\UserLookupController;
use Illuminate\Support\Facades\Route;

// Public API routes (no authentication required)
// Rate limited to prevent brute force and mass account creation
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected API routes (authentication required)
Route::middleware(['auth:sanctum', 'token.api', 'throttle:api'])->group(function () {
    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);

    // User profile
    Route::get('/user', [UserController::class, 'show']);
    Route::patch('/user', [UserController::class, 'update']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);

    // MCP tokens for AI assistant clients (requires ai_assistant feature flag)
    Route::middleware('feature.ai')->group(function () {
        Route::get('/mcp/tokens', [McpTokenController::class, 'index']);
        Route::post('/mcp/tokens', [McpTokenController::class, 'store']);
        Route::delete('/mcp/tokens/{tokenId}', [McpTokenController::class, 'destroy']);
    });

    // Favourite contacts (quick-pick for task assignment)
    Route::post('/favourites/{user}', [FavouriteController::class, 'toggle'])->middleware('throttle:user-search');

    // User lookup (for task assignment - only shows connected users)
    // Extra rate limiting to prevent enumeration attacks
    Route::get('/users/lookup', UserLookupController::class)->middleware('throttle:user-search');

    // User discovery (for finding users to connect with - exact email/phone match)
    // Extra rate limiting to prevent enumeration attacks
    Route::post('/users/discover', UserDiscoveryController::class)->middleware('throttle:user-search');

    // Notifications (poll-based)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'dismiss']);

    // Push notifications (Web Push subscriptions)
    Route::get('/push/vapid-key', [PushSubscriptionController::class, 'vapidKey']);
    Route::get('/push/subscriptions', [PushSubscriptionController::class, 'index']);
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
    Route::delete('/push/subscriptions/{subscription}', [PushSubscriptionController::class, 'destroy']);

    // Connections (mutual consent for task assignment)
    // Extra rate limiting on store to prevent spam requests
    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('throttle:connections');
    Route::post('/connections/{connection}/accept', [ConnectionController::class, 'accept']);
    Route::post('/connections/{connection}/decline', [ConnectionController::class, 'decline']);
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy']);

    // Projects
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive']);
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore']);

    // Items (specific routes before resource to ensure proper matching)
    Route::post('items/reorder', [ItemController::class, 'reorder']);
    Route::post('items/{item}/generate-recurrences', [ItemController::class, 'generateRecurrences']);
    Route::apiResource('items', ItemController::class);

    // Tags
    Route::apiResource('tags', TagController::class);

    // Activity log
    Route::get('/activity-log', [ActivityLogController::class, 'index']);

    // Admin routes
    Route::prefix('admin')->middleware(['admin'])->group(function () {
        Route::apiResource('users', AdminUserController::class);
        Route::get('settings/features', [AdminSettingsController::class, 'showFeatures']);
        Route::put('settings/features', [AdminSettingsController::class, 'updateFeatures']);
    });
});
