<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class UserController extends Controller
{
    /**
     * Display a listing of users (Inertia for web, JSON for API).
     */
    public function index(Request $request): InertiaResponse|AnonymousResourceCollection
    {
        $query = User::query()
            ->select(['id', 'name', 'email', 'phone', 'is_admin', 'email_verified_at', 'created_at'])
            ->when($request->search, function (Builder $query, string $search) {
                // Escape LIKE special characters to prevent pattern injection
                // Use '!' as escape character for cross-database compatibility
                $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
                $query->where(function (Builder $q) use ($escapedSearch) {
                    $q->whereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", ["%{$escapedSearch}%"])
                        ->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", ["%{$escapedSearch}%"])
                        ->orWhereRaw("LOWER(phone) LIKE LOWER(?) ESCAPE '!'", ["%{$escapedSearch}%"]);
                });
            })
            ->when($request->has('is_admin'), function (Builder $query) use ($request) {
                $query->where('is_admin', $request->boolean('is_admin'));
            })
            ->when($request->sort ?? 'created_at', function (Builder $query, string $sort) use ($request) {
                $direction = $request->direction ?? 'desc';
                $query->orderBy($sort, $direction);
            });

        $users = $query
            ->withCount(['items', 'projects', 'tags'])
            ->paginate($request->per_page ?? 25)
            ->withQueryString();

        // Return JSON for API requests
        if ($request->expectsJson()) {
            return UserResource::collection($users);
        }

        // Return Inertia for web requests
        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => $request->only(['search', 'is_admin', 'sort', 'direction']),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
        ]);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified user (Inertia).
     */
    public function show(User $user): InertiaResponse
    {
        // Shared projects (projects with items assigned to/from this user)
        $sharedProjects = Project::query()
            ->where(function (Builder $q) use ($user) {
                $q->whereHas('items', fn ($q) => $q->where('assignee_id', $user->id))
                    ->orWhereHas('items', fn ($q) => $q->where('user_id', $user->id)
                        ->whereNotNull('assignee_id'));
            })
            ->with(['user:id,name'])
            ->limit(10)
            ->get();

        // Assigned tasks (items where user is assignee)
        $assignedTasks = Item::query()
            ->where('assignee_id', $user->id)
            ->with(['user:id,name', 'project:id,name,color'])
            ->latest()
            ->limit(20)
            ->get();

        // Delegated tasks (items created by user and assigned to others)
        $delegatedTasks = Item::query()
            ->where('user_id', $user->id)
            ->whereNotNull('assignee_id')
            ->with(['assignee:id,name', 'project:id,name,color'])
            ->latest()
            ->limit(20)
            ->get();

        // Connections
        $connections = $user->connections();

        return Inertia::render('admin/users/show', [
            'user' => new UserResource($user),
            'statistics' => [
                'items_count' => $user->items()->count(),
                'projects_count' => $user->projects()->count(),
                'tags_count' => $user->tags()->count(),
                'assigned_items_count' => $user->assignedItems()->count(),
                'connections_count' => $connections->count(),
            ],
            'sharedProjects' => $sharedProjects,
            'assignedTasks' => $assignedTasks,
            'delegatedTasks' => $delegatedTasks,
            'connections' => $connections,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => ['sometimes', 'required', 'confirmed', Password::defaults()],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return new UserResource($user);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user): RedirectResponse|Response
    {
        // Prevent deleting self
        if ((string) $user->id === (string) $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot delete your own account.');
        }

        $user->delete();

        // Return 204 for API requests
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        // Return redirect for web requests
        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * Hard reset user data (delete all user's resources).
     */
    public function hardReset(Request $request, User $user): RedirectResponse
    {
        // Prevent resetting self
        if ((string) $user->id === (string) $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot hard reset your own account.');
        }

        DB::transaction(function () use ($user, $request) {
            // Delete user's items, projects, tags, notifications, connections
            $user->items()->delete();
            $user->projects()->delete();
            $user->tags()->delete();
            $user->taskNotifications()->delete();
            $user->sentConnectionRequests()->delete();
            $user->receivedConnectionRequests()->delete();
            $user->pushSubscriptions()->delete();

            // Log the hard reset (preserve actor info for audit trail)
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'user_hard_reset',
                'resource_type' => 'user',
                'resource_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'success',
                'metadata' => [
                    'actor_name' => $request->user()->name,
                    'actor_email' => $request->user()->email,
                    'reset_user_email' => $user->email,
                    'reset_user_name' => $user->name,
                ],
            ]);
        });

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User data has been reset successfully.');
    }
}
