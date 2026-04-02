<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\NotificationServiceInterface;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    /**
     * Get all notifications for the authenticated user.
     * Use ?unread_only=true to get only unread notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = $user->taskNotifications()->orderBy('created_at', 'desc');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->limit(50)->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'unread_count' => $this->notificationService->getUnreadCount($user),
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Notification $notification): JsonResponse
    {
        // Ensure the notification belongs to the authenticated user
        // Cast to string for reliable UUID comparison
        if ((string) $notification->user_id !== (string) Auth::id()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => $this->notificationService->getUnreadCount(Auth::user()),
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(): JsonResponse
    {
        $count = $this->notificationService->markAsRead(Auth::user());

        return response()->json([
            'message' => 'All notifications marked as read.',
            'marked_count' => $count,
        ]);
    }

    /**
     * Dismiss (delete) a specific notification.
     */
    public function dismiss(Notification $notification): JsonResponse
    {
        if ((string) $notification->user_id !== (string) Auth::id()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification dismissed.',
            'unread_count' => $this->notificationService->getUnreadCount(Auth::user()),
        ]);
    }
}
