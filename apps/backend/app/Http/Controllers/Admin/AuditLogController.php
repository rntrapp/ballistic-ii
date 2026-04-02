<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class AuditLogController extends Controller
{
    /**
     * Display a paginated list of audit logs.
     */
    public function __invoke(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:success,failed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = AuditLog::query()
            ->with('user:id,name,email')
            ->when($validated['user_id'] ?? null, function (Builder $query, string $userId) {
                $query->where('user_id', $userId);
            })
            ->when($validated['action'] ?? null, function (Builder $query, string $action) {
                $query->where('action', $action);
            })
            ->when($validated['status'] ?? null, function (Builder $query, string $status) {
                $query->where('status', $status);
            })
            ->when($validated['date_from'] ?? null, function (Builder $query, string $dateFrom) {
                $query->where('created_at', '>=', $dateFrom);
            })
            ->when($validated['date_to'] ?? null, function (Builder $query, string $dateTo) {
                $query->where('created_at', '<=', $dateTo);
            })
            ->latest();

        $logs = $query->paginate($validated['per_page'] ?? 50)->withQueryString();

        // Cache unique actions for filter dropdown (busted every 60 seconds)
        $actions = Cache::remember('audit_log_actions', 60, function () {
            return AuditLog::distinct()->pluck('action')->sort()->values();
        });

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $logs,
            'filters' => $request->only(['user_id', 'action', 'status', 'date_from', 'date_to']),
            'actions' => $actions,
        ]);
    }
}
