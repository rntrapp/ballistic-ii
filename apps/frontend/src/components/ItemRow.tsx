"use client";

import { cycleStatus } from "@/lib/status";
import { updateStatus } from "@/lib/api";
import type { Item } from "@/types";
import { useOptimistic, startTransition, useEffect, useRef } from "react";
import { StatusCircle } from "./StatusCircle";
import { useFeatureFlags } from "@/hooks/useFeatureFlags";

type Props = {
  item: Item;
  onChange: (itemOrUpdater: Item | ((current: Item) => Item)) => void;
  onOptimisticReorder: (
    itemId: string,
    direction: "up" | "down" | "top",
  ) => void;
  index: number;
  onEdit: () => void;
  isFirst: boolean;
  onDragStart: (id: string) => void;
  onDragEnter: (id: string) => void;
  onDropItem: (id: string) => void;
  onDragEnd: () => void;
  draggingId: string | null;
  dragOverId: string | null;
  onError: (message: string) => void;
};

export function ItemRow({
  item,
  onChange,
  onOptimisticReorder,
  index,
  onEdit,
  isFirst,
  onDragStart,
  onDragEnter,
  onDropItem,
  onDragEnd,
  draggingId,
  dragOverId,
  onError,
}: Props) {
  const { dates, delegation } = useFeatureFlags();
  const pendingStatusRef = useRef<Item["status"] | null>(null);
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const [optimisticItem, addOptimistic] = useOptimistic(
    item,
    (currentItem: Item, newStatus: Item["status"]) => ({
      ...currentItem,
      status: newStatus,
      completed_at:
        newStatus === "done"
          ? new Date().toISOString()
          : currentItem.status === "done"
            ? null
            : currentItem.completed_at,
    }),
  );

  useEffect(() => {
    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
    };
  }, []);

  function onToggle() {
    const nextStatus = cycleStatus(optimisticItem.status);

    const completedAt =
      nextStatus === "done" || nextStatus === "wontdo"
        ? new Date().toISOString()
        : optimisticItem.status === "done" || optimisticItem.status === "wontdo"
          ? null
          : optimisticItem.completed_at;

    // Update UI immediately (optimistic update)
    startTransition(() => {
      addOptimistic(nextStatus);
    });

    // Update parent state immediately
    onChange((current) => {
      if (current.id !== item.id) return current;
      return {
        ...current,
        status: nextStatus,
        completed_at: completedAt,
      };
    });

    pendingStatusRef.current = nextStatus;

    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
    }

    debounceTimerRef.current = setTimeout(() => {
      const statusToSend = pendingStatusRef.current;
      if (!statusToSend) return;

      updateStatus(item.id, statusToSend)
        .then((serverItem) => {
          onChange((current) => {
            if (current.id !== item.id) return current;
            if (current.status !== statusToSend) return current;
            return {
              ...current,
              status: serverItem.status,
              completed_at: serverItem.completed_at,
              updated_at: serverItem.updated_at,
            };
          });
        })
        .catch((error) => {
          console.error("Failed to update status:", error);
          onChange(item);
          onError("Failed to update status. Change reverted.");
        })
        .finally(() => {
          if (pendingStatusRef.current === statusToSend) {
            pendingStatusRef.current = null;
          }
        });
    }, 3000);
  }

  function onMove(direction: "up" | "down" | "top") {
    onOptimisticReorder(item.id, direction);
  }

  const isCompleted = optimisticItem.status === "done";
  const isCancelled = optimisticItem.status === "wontdo";

  // Urgency calculation
  const urgency = (() => {
    if (!optimisticItem.due_date || isCompleted || isCancelled) return "none";
    const today = new Date();
    const todayStr = today.toISOString().split("T")[0];
    const dueMs = new Date(optimisticItem.due_date + "T23:59:59").getTime();
    const in72hMs = today.getTime() + 72 * 60 * 60 * 1000;

    if (optimisticItem.due_date < todayStr) return "overdue";
    if (dueMs <= in72hMs) return "due-soon";
    return "upcoming";
  })();

  // Get project name from nested project object if available
  const projectName = optimisticItem.project?.name || null;
  const isDragging = draggingId === item.id;
  const isDragOver = dragOverId === item.id && draggingId !== item.id;

  return (
    <div
      data-item-id={item.id}
      className={`flex items-center gap-3 rounded-md bg-white p-3 shadow-sm transition-all duration-300 ease-out hover:shadow-md hover:-translate-y-0.5 animate-slide-in-up cursor-pointer ${isDragging ? "scale-105 shadow-xl ring-2 ring-[var(--blue)]/40 bg-blue-50/50 z-50" : ""} ${isDragOver ? "ring-2 ring-[var(--blue)]/60 bg-blue-50/30" : ""} ${dates && urgency === "overdue" ? "border-l-4 border-l-red-500 bg-red-50/50" : ""} ${dates && urgency === "due-soon" ? "border-l-4 border-l-amber-400 bg-amber-50/30" : ""}`}
      style={{
        animationDelay: `${index * 50}ms`,
      }}
      onClick={onEdit}
      draggable
      onDragStart={(event) => {
        event.stopPropagation();
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", item.id);
        }
        onDragStart(item.id);
      }}
      onDragEnter={(event) => {
        event.preventDefault();
        event.stopPropagation();
        onDragEnter(item.id);
      }}
      onDragOver={(event) => {
        event.preventDefault();
      }}
      onDrop={(event) => {
        event.preventDefault();
        event.stopPropagation();
        onDragEnter(item.id);
        onDropItem(item.id);
      }}
      onDragEnd={(event) => {
        event.preventDefault();
        event.stopPropagation();
        onDragEnd();
      }}
    >
      {/* Status circle */}
      <div className="shrink-0" onClick={(e) => e.stopPropagation()}>
        <StatusCircle status={optimisticItem.status} onClick={onToggle} />
      </div>

      {/* Task details */}
      <div className="flex-1 min-w-0">
        <div
          className={`font-reading flex items-center gap-1 font-medium transition-colors duration-200 ${isCompleted || isCancelled ? "text-slate-400 line-through" : "text-[var(--navy)]"}`}
        >
          {optimisticItem.title}
          {dates &&
            (optimisticItem.is_recurring_template ||
              optimisticItem.is_recurring_instance) && (
              <svg
                viewBox="0 0 24 24"
                width="14"
                height="14"
                fill="none"
                stroke="currentColor"
                className={`shrink-0 ${isCompleted || isCancelled ? "text-slate-300" : "text-slate-400"}`}
              >
                <path
                  d="M17 1l4 4-4 4"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
                <path
                  d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
                <path
                  d="M21 13v2a4 4 0 0 1-4 4H3"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            )}
        </div>
        {/* Project, tags, and assignment badges */}
        <div className="flex flex-wrap gap-1.5 mt-1">
          {projectName && (
            <span
              className={`text-xs px-2 py-0.5 rounded-full transition-colors duration-200 ${isCompleted || isCancelled ? "bg-slate-100 text-slate-300" : "bg-[var(--blue)]/10 text-[var(--blue-600)]"}`}
            >
              {projectName}
            </span>
          )}
          {/* Tags */}
          {optimisticItem.tags?.map((tag) => (
            <span
              key={tag.id}
              className={`text-xs px-2 py-0.5 rounded-full transition-colors duration-200 ${isCompleted || isCancelled ? "bg-slate-100 text-slate-300" : "bg-violet-100 text-violet-700"}`}
              style={
                tag.color && !isCompleted && !isCancelled
                  ? {
                      backgroundColor: `${tag.color}20`,
                      color: tag.color,
                    }
                  : undefined
              }
            >
              {tag.name}
            </span>
          ))}
          {delegation &&
            optimisticItem.is_delegated &&
            optimisticItem.assignee && (
              <span
                className={`text-xs font-medium px-2 py-0.5 rounded-full transition-colors duration-200 ${isCompleted || isCancelled ? "bg-slate-100 text-slate-300" : "bg-amber-100 text-amber-700 border border-amber-200"}`}
              >
                → {optimisticItem.assignee.name}
              </span>
            )}
          {delegation &&
            optimisticItem.is_assigned &&
            !optimisticItem.is_delegated &&
            optimisticItem.owner && (
              <span
                className={`text-xs font-medium px-2 py-0.5 rounded-full transition-colors duration-200 ${isCompleted || isCancelled ? "bg-slate-100 text-slate-300" : "bg-emerald-100 text-emerald-700 border border-emerald-200"}`}
              >
                ← {optimisticItem.owner.name}
              </span>
            )}
        </div>
        {optimisticItem.description && (
          <div
            className={`font-reading mt-1 text-sm transition-colors duration-200 ${isCompleted || isCancelled ? "text-slate-300" : "text-slate-500"}`}
          >
            {optimisticItem.description.length > 50
              ? `${optimisticItem.description.slice(0, 50)}...`
              : optimisticItem.description}
          </div>
        )}
        {delegation && optimisticItem.assignee_notes && (
          <div
            className={`font-reading text-sm mt-1 italic transition-colors duration-200 ${isCompleted || isCancelled ? "text-slate-300" : "text-slate-400"}`}
          >
            <span className="text-xs font-medium not-italic text-slate-500">
              Note:{" "}
            </span>
            {optimisticItem.assignee_notes.length > 80
              ? `${optimisticItem.assignee_notes.slice(0, 80)}...`
              : optimisticItem.assignee_notes}
          </div>
        )}
        {dates && optimisticItem.due_date && !isCompleted && !isCancelled && (
          <div
            className={`text-xs mt-1 flex items-center gap-1 ${
              urgency === "overdue"
                ? "text-red-600 font-medium"
                : urgency === "due-soon"
                  ? "text-amber-600"
                  : "text-slate-400"
            }`}
          >
            {urgency === "overdue" && (
              <span className="inline-block w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
            )}
            {urgency === "overdue" ? "Overdue" : "Due"}{" "}
            {new Date(optimisticItem.due_date + "T00:00:00").toLocaleDateString(
              "en-AU",
              {
                day: "numeric",
                month: "short",
              },
            )}
          </div>
        )}
        {dates &&
          optimisticItem.scheduled_date &&
          !isCompleted &&
          !isCancelled && (
            <div className="text-xs mt-0.5 text-slate-400">
              Scheduled{" "}
              {new Date(
                optimisticItem.scheduled_date + "T00:00:00",
              ).toLocaleDateString("en-AU", {
                day: "numeric",
                month: "short",
              })}
            </div>
          )}
      </div>

      {/* Move controls - always visible */}
      <div
        className="flex items-center gap-1"
        onClick={(e) => e.stopPropagation()}
      >
        {!isFirst && (
          <button
            className="tap-target inline-flex size-8 min-h-8 min-w-8 flex-none items-center justify-center rounded-md bg-slate-100 text-slate-700 transition-all duration-200 hover:bg-slate-200 active:scale-95"
            onClick={() => onMove("top")}
            aria-label="Move to top"
          >
            ⇈
          </button>
        )}
        <span
          className="tap-target inline-flex size-8 min-h-8 min-w-8 flex-none items-center justify-center rounded-md bg-slate-100 text-slate-500 transition-all duration-200 hover:bg-slate-200 hover:text-slate-700 active:scale-95 cursor-grab"
          aria-label="Drag to reorder"
        >
          <svg
            viewBox="0 0 24 24"
            width="16"
            height="16"
            fill="currentColor"
            className="block"
          >
            <circle cx="8" cy="7" r="1.4" />
            <circle cx="16" cy="7" r="1.4" />
            <circle cx="8" cy="12" r="1.4" />
            <circle cx="16" cy="12" r="1.4" />
            <circle cx="8" cy="17" r="1.4" />
            <circle cx="16" cy="17" r="1.4" />
          </svg>
        </span>
      </div>
    </div>
  );
}
