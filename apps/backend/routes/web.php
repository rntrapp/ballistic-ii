<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Web dashboard is restricted to admin users only
// Regular users should use the API and mobile/desktop apps
Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    // Dashboard shows system health metrics for admins
    Route::get('dashboard', HealthController::class)->name('dashboard');

    // Admin routes
    Route::prefix('admin')->name('admin.')->group(function () {
        // User management
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/hard-reset', [UserController::class, 'hardReset'])->name('users.hard-reset');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Health dashboard
        Route::get('health', HealthController::class)->name('health.index');

        // Feature flags
        Route::get('feature-flags', [FeatureFlagController::class, 'index'])->name('feature-flags.index');
        Route::put('feature-flags', [FeatureFlagController::class, 'update'])->name('feature-flags.update');

        // Audit logs
        Route::get('audit-logs', AuditLogController::class)->name('audit-logs.index');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
