"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { Notification, NotificationsResponse } from "@/types";
import {
  fetchNotifications,
  markNotificationAsRead,
  markAllNotificationsAsRead,
  dismissNotification,
} from "@/lib/api";

interface NotificationCentreProps {
  delegation: boolean;
}

export function NotificationCentre({ delegation }: NotificationCentreProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    try {
      const response: NotificationsResponse = await fetchNotifications();
      setNotifications(response.data);
      setUnreadCount(response.unread_count);
    } catch (err) {
      console.error("Failed to fetch notifications:", err);
    }
  }, []);

  // Poll for new notifications every 30s when delegation is on
  useEffect(() => {
    if (!delegation) {
      setNotifications([]);
      setUnreadCount(0);
      return;
    }

    void load();
    pollRef.current = setInterval(() => void load(), 30_000);

    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [delegation, load]);

  // Close on outside click
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(e.target as Node)
      ) {
        setIsOpen(false);
      }
    }

    if (isOpen) {
      document.addEventListener("mousedown", handleClick);
      return () => document.removeEventListener("mousedown", handleClick);
    }
  }, [isOpen]);

  // Close on Escape
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") setIsOpen(false);
    }

    if (isOpen) {
      document.addEventListener("keydown", handleKeyDown);
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
  }, [isOpen]);

  async function handleMarkAsRead(id: string) {
    try {
      const result = await markNotificationAsRead(id);
      setNotifications((prev) =>
        prev.map((n) =>
          n.id === id ? { ...n, read_at: new Date().toISOString() } : n,
        ),
      );
      setUnreadCount(result.unread_count);
    } catch (err) {
      console.error("Failed to mark notification as read:", err);
    }
  }

  async function handleMarkAllAsRead() {
    try {
      await markAllNotificationsAsRead();
      setNotifications((prev) =>
        prev.map((n) => ({
          ...n,
          read_at: n.read_at || new Date().toISOString(),
        })),
      );
      setUnreadCount(0);
    } catch (err) {
      console.error("Failed to mark all as read:", err);
    }
  }

  async function handleDismiss(id: string) {
    try {
      const result = await dismissNotification(id);
      setNotifications((prev) => prev.filter((n) => n.id !== id));
      setUnreadCount(result.unread_count);
    } catch (err) {
      console.error("Failed to dismiss notification:", err);
    }
  }

  async function handleToggle() {
    if (!isOpen && !loading) {
      setLoading(true);
      await load();
      setLoading(false);
    }
    setIsOpen((prev) => !prev);
  }

  if (!delegation) return null;

  return (
    <div ref={dropdownRef} className="relative">
      {/* Bell button */}
      <button
        type="button"
        aria-label="Notifications"
        onClick={() => void handleToggle()}
        className="tap-target grid h-10 w-10 place-items-center rounded-md hover:bg-slate-100 active:scale-95 transition-all duration-200 relative"
      >
        <svg
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          className="text-[var(--navy)]"
        >
          <path
            d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"
            strokeWidth="1.4"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
        {unreadCount > 0 && (
          <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </button>

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute bottom-full right-0 mb-2 w-80 max-h-96 overflow-y-auto rounded-xl bg-white shadow-xl border border-slate-200/50 animate-slide-in-up z-50">
          {/* Header */}
          <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
            <h3 className="text-sm font-semibold text-gray-900">
              Notifications
            </h3>
            {unreadCount > 0 && (
              <button
                type="button"
                onClick={() => void handleMarkAllAsRead()}
                className="text-xs text-blue-600 hover:text-blue-800 font-medium"
              >
                Mark all as read
              </button>
            )}
          </div>

          {/* Loading */}
          {loading && notifications.length === 0 && (
            <p className="text-sm text-gray-500 text-center py-6">Loading...</p>
          )}

          {/* Empty */}
          {!loading && notifications.length === 0 && (
            <p className="text-sm text-gray-500 text-center py-6">
              No notifications yet.
            </p>
          )}

          {/* List */}
          {notifications.length > 0 && (
            <div className="divide-y divide-gray-50">
              {notifications.map((notification) => (
                <div
                  key={notification.id}
                  className={`flex items-start gap-3 px-4 py-3 transition-colors ${
                    !notification.read_at ? "bg-blue-50/50" : "hover:bg-gray-50"
                  }`}
                >
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-gray-900">
                      {notification.title}
                    </p>
                    <p className="text-xs text-gray-600 mt-0.5">
                      {notification.message}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                      {formatTimeAgo(notification.created_at)}
                    </p>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    {!notification.read_at && (
                      <button
                        type="button"
                        onClick={() => void handleMarkAsRead(notification.id)}
                        className="p-1 rounded hover:bg-blue-100 transition-colors"
                        title="Mark as read"
                      >
                        <svg
                          viewBox="0 0 24 24"
                          width="14"
                          height="14"
                          fill="none"
                          stroke="currentColor"
                          className="text-blue-600"
                        >
                          <path
                            d="M20 6 9 17l-5-5"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          />
                        </svg>
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => void handleDismiss(notification.id)}
                      className="p-1 rounded hover:bg-red-100 transition-colors"
                      title="Dismiss"
                    >
                      <svg
                        viewBox="0 0 24 24"
                        width="14"
                        height="14"
                        fill="none"
                        stroke="currentColor"
                        className="text-gray-400"
                      >
                        <path
                          d="M18 6 6 18M6 6l12 12"
                          strokeWidth="2"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function formatTimeAgo(iso: string): string {
  const now = Date.now();
  const then = new Date(iso).getTime();
  const diffMs = now - then;
  const diffMins = Math.floor(diffMs / 60_000);
  const diffHours = Math.floor(diffMs / 3_600_000);
  const diffDays = Math.floor(diffMs / 86_400_000);

  if (diffMins < 1) return "Just now";
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;

  return new Date(iso).toLocaleDateString("en-AU", {
    day: "numeric",
    month: "short",
  });
}
