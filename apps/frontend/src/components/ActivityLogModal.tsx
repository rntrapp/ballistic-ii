"use client";

import { useCallback, useEffect, useRef } from "react";
import { FocusTrap } from "focus-trap-react";
import { useModal } from "@/hooks/useModal";
import { useCursorPagination } from "@/hooks/useCursorPagination";
import { fetchActivityLog, type ActivityLogItem } from "@/lib/api";

interface ActivityLogModalProps {
  isOpen: boolean;
  onClose: () => void;
}

const STATUS_LABELS: Record<string, string> = {
  todo: "To Do",
  doing: "Doing",
  done: "Done",
  wontdo: "Won\u2019t Do",
};

const STATUS_COLOURS: Record<string, string> = {
  todo: "bg-slate-200 text-slate-700",
  doing: "bg-blue-100 text-blue-700",
  done: "bg-green-100 text-green-700",
  wontdo: "bg-red-100 text-red-700",
};

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-AU", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function getStatusActionLabel(status: ActivityLogItem["status"]): string {
  return status === "done" ? "Marked done" : "Marked won’t do";
}

function getAssignmentLabel(item: ActivityLogItem): string | null {
  if (item.is_assigned_to_me && item.owner) {
    return `Assigned by ${item.owner.name}`;
  }

  if (item.is_delegated && item.assignee) {
    return `Assigned to ${item.assignee.name}`;
  }

  return null;
}

export function getActivityTimestamp(item: ActivityLogItem): string {
  return item.activity_at || item.completed_at || item.updated_at;
}

export function ActivityLogModal({ isOpen, onClose }: ActivityLogModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  useModal(isOpen);

  const fetchFn = useCallback(
    (cursor?: string) => fetchActivityLog(cursor),
    [],
  );

  const {
    items,
    isLoading,
    isLoadingMore,
    hasMore,
    error,
    loadInitial,
    loadMore,
    reset,
  } = useCursorPagination<ActivityLogItem>(fetchFn);

  useEffect(() => {
    if (isOpen) {
      reset();
      void loadInitial();
    }
  }, [isOpen, loadInitial, reset]);

  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }

    if (isOpen) {
      document.addEventListener("keydown", handleKeyDown);
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
  }, [isOpen, onClose]);

  function handleBackdropClick(e: React.MouseEvent) {
    if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
      onClose();
    }
  }

  if (!isOpen) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm animate-fade-in p-4"
      onClick={handleBackdropClick}
    >
      <FocusTrap focusTrapOptions={{ allowOutsideClick: true }}>
        <div
          ref={modalRef}
          className="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl animate-slide-in-up"
        >
          {/* Header */}
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-gray-900">
              Activity Log
            </h2>
            <button
              type="button"
              onClick={onClose}
              className="rounded-full p-2 hover:bg-gray-100 transition-colors"
              aria-label="Close"
            >
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className="text-gray-500"
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

          {/* Error */}
          {error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
              {error}
            </div>
          )}

          {/* Loading */}
          {isLoading && (
            <p className="text-sm text-gray-500 text-center py-8">
              Loading activity...
            </p>
          )}

          {/* Empty state */}
          {!isLoading && items.length === 0 && !error && (
            <p className="text-sm text-gray-500 text-center py-8">
              No activity yet.
            </p>
          )}

          {/* Items */}
          {items.length > 0 && (
            <div className="space-y-2">
              {items.map((item) => (
                <div
                  key={item.id}
                  className="flex items-start gap-3 p-3 rounded-lg bg-gray-50 border border-gray-100"
                >
                  <div className="min-w-0 flex-1">
                    <p className="font-reading text-sm font-medium text-gray-900 truncate">
                      {item.title}
                    </p>
                    <div className="flex items-center gap-2 mt-1">
                      <span
                        className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLOURS[item.status] || "bg-gray-100 text-gray-600"}`}
                      >
                        {STATUS_LABELS[item.status] || item.status}
                      </span>
                      {item.project && (
                        <span className="flex items-center gap-1 text-xs text-gray-500">
                          {item.project.color && (
                            <span
                              className="inline-block h-2 w-2 rounded-full"
                              style={{ backgroundColor: item.project.color }}
                            />
                          )}
                          {item.project.name}
                        </span>
                      )}
                    </div>
                    {getAssignmentLabel(item) && (
                      <p className="mt-1 text-xs text-slate-500">
                        {getAssignmentLabel(item)}
                      </p>
                    )}
                    <p className="mt-1 text-xs text-slate-500">
                      {item.completed_by?.name
                        ? `${getStatusActionLabel(item.status)} by ${item.completed_by.name}`
                        : getStatusActionLabel(item.status)}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                      {formatDate(getActivityTimestamp(item))}
                    </p>
                  </div>
                </div>
              ))}

              {/* Load more */}
              {hasMore && (
                <button
                  type="button"
                  onClick={() => void loadMore()}
                  disabled={isLoadingMore}
                  className="w-full py-2 text-sm text-blue-600 hover:text-blue-800 disabled:opacity-50 transition-colors"
                >
                  {isLoadingMore ? "Loading..." : "Load more"}
                </button>
              )}
            </div>
          )}
        </div>
      </FocusTrap>
    </div>
  );
}
