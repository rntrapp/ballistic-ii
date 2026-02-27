"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useFeatureFlags } from "@/hooks/useFeatureFlags";
import { useRouter } from "next/navigation";
import type { Item, ItemScope, Project, CognitivePhaseResponse } from "@/types";
import {
  fetchItems,
  createItem,
  updateItem,
  reorderItems,
  fetchProjects,
  createProject,
  fetchCognitivePhase,
} from "@/lib/api";
import { ItemRow } from "@/components/ItemRow";
import { EmptyState } from "@/components/EmptyState";
import { SplashScreen } from "@/components/SplashScreen";
import { SettingsModal } from "@/components/SettingsModal";
import { NotesModal } from "@/components/NotesModal";
import { EditItemModal } from "@/components/EditItemModal";
import { CognitiveWave } from "@/components/CognitiveWave";
import { useAuth } from "@/contexts/AuthContext";

function normaliseItemResponse(payload: Item | { data?: Item }): Item {
  if (
    payload &&
    typeof payload === "object" &&
    "data" in payload &&
    (payload as { data?: Item }).data
  ) {
    return (payload as { data: Item }).data;
  }
  return payload as Item;
}

function getUrgencyRank(item: Item, todayStr: string, in72hMs: number): number {
  if (!item.due_date) return 4; // No deadline = lowest priority

  const dueMs = new Date(item.due_date + "T23:59:59").getTime();

  if (item.due_date < todayStr) return 1; // Overdue
  if (dueMs <= in72hMs) return 2; // Due within 72 hours
  return 3; // Has deadline, not urgent yet
}

function sortByUrgency(items: Item[]): Item[] {
  const now = new Date();
  const todayStr = now.toISOString().split("T")[0]; // YYYY-MM-DD
  const in72hMs = now.getTime() + 72 * 60 * 60 * 1000;

  return [...items].sort((a, b) => {
    const urgencyA = getUrgencyRank(a, todayStr, in72hMs);
    const urgencyB = getUrgencyRank(b, todayStr, in72hMs);

    if (urgencyA !== urgencyB) {
      return urgencyA - urgencyB; // Lower rank = higher priority
    }

    // Within the same urgency tier, sort by due_date ascending (nearest deadline first)
    if (a.due_date && b.due_date) {
      return a.due_date.localeCompare(b.due_date);
    }
    if (a.due_date) return -1;
    if (b.due_date) return 1;

    // Finally, fall back to position
    return a.position - b.position;
  });
}

function applyCognitiveSort(items: Item[], phase: string): Item[] {
  return [...items].sort((a, b) => {
    const scoreA = a.cognitive_load_score ?? 5;
    const scoreB = b.cognitive_load_score ?? 5;
    if (phase === "Trough") return scoreA - scoreB; // easy first
    if (phase === "Peak") return scoreB - scoreA; // hard first
    return 0; // Recovery: no reorder
  });
}

export default function Home() {
  const router = useRouter();
  const {
    isAuthenticated,
    isLoading: authLoading,
    logout,
    user,
    refreshUser,
  } = useAuth();
  const [items, setItems] = useState<Item[]>([]);
  const [assignedItems, setAssignedItems] = useState<Item[]>([]);
  const [delegatedItems, setDelegatedItems] = useState<Item[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [showEditModal, setShowEditModal] = useState(false);
  const [editingItem, setEditingItem] = useState<Item | null>(null);
  const [loading, setLoading] = useState(true);
  const [scrollToItemId, setScrollToItemId] = useState<string | null>(null);
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [dragOverId, setDragOverId] = useState<string | null>(null);
  const dragSourceRef = useRef<string | null>(null);
  const dragOverRef = useRef<string | null>(null);
  const dropHandledRef = useRef(false);
  const [viewScope, setViewScope] = useState<ItemScope>("active");
  const [filterProjectId, setFilterProjectId] = useState<string | null>(null);
  const [showFilter, setShowFilter] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const toastTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [showSettings, setShowSettings] = useState(false);
  const [showNotes, setShowNotes] = useState(false);
  const { dates, delegation, cognitivePhase } = useFeatureFlags();
  const [cognitivePhaseData, setCognitivePhaseData] =
    useState<CognitivePhaseResponse | null>(null);

  const showError = useCallback((message: string) => {
    if (toastTimeoutRef.current) clearTimeout(toastTimeoutRef.current);
    setToast(message);
    toastTimeoutRef.current = setTimeout(() => setToast(null), 4000);
  }, []);

  // Reset view scope when dates feature is turned off
  useEffect(() => {
    if (!dates && viewScope !== "active") {
      setViewScope("active");
    }
  }, [dates, viewScope]);

  // Redirect to login if not authenticated
  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/login");
    }
  }, [authLoading, isAuthenticated, router]);

  // Fetch items and projects when authenticated
  useEffect(() => {
    if (isAuthenticated) {
      setLoading(true);
      Promise.all([
        fetchItems({ scope: viewScope }),
        delegation
          ? fetchItems({ assigned_to_me: true, scope: viewScope })
          : Promise.resolve([]),
        delegation
          ? fetchItems({ delegated: true, scope: viewScope })
          : Promise.resolve([]),
        fetchProjects(),
      ])
        .then(([itemsData, assignedData, delegatedData, projectsData]) => {
          setItems(itemsData);
          setAssignedItems(assignedData);
          setDelegatedItems(delegatedData);
          setProjects(projectsData);
        })
        .catch((error) => {
          console.error("Failed to fetch data:", error);
        })
        .finally(() => setLoading(false));
    }
  }, [isAuthenticated, viewScope, dates, delegation]);

  // Fetch cognitive phase data when feature flag is enabled
  useEffect(() => {
    if (isAuthenticated && cognitivePhase) {
      fetchCognitivePhase().then(setCognitivePhaseData).catch(console.error);
    } else {
      setCognitivePhaseData(null);
    }
  }, [isAuthenticated, cognitivePhase]);

  // Handle scrolling to newly added items
  useEffect(() => {
    if (scrollToItemId) {
      const timer = setTimeout(() => {
        const element = document.querySelector(
          `[data-item-id="${CSS.escape(scrollToItemId)}"]`,
        );
        if (element) {
          element.scrollIntoView({ behavior: "smooth", block: "center" });
        }
        setScrollToItemId(null);
      }, 100);

      return () => clearTimeout(timer);
    }
  }, [scrollToItemId]);

  // Listen for service worker messages (real-time task updates from push notifications)
  useEffect(() => {
    if (typeof navigator === "undefined" || !navigator.serviceWorker) return;

    function handleSwMessage(event: MessageEvent) {
      if (event.data?.type !== "TASK_UPDATE") return;

      const notificationType: string = event.data.notificationType ?? "";

      if (
        notificationType === "task_unassigned" ||
        notificationType === "task_rejected"
      ) {
        // Refresh both assigned and delegated lists
        Promise.all([
          fetchItems({ assigned_to_me: true, scope: viewScope }),
          fetchItems({ delegated: true, scope: viewScope }),
        ])
          .then(([assignedData, delegatedData]) => {
            setAssignedItems(assignedData);
            setDelegatedItems(delegatedData);
          })
          .catch(console.error);
      } else {
        // task_assigned, task_updated, task_completed, etc.
        fetchItems({ assigned_to_me: true, scope: viewScope })
          .then(setAssignedItems)
          .catch(console.error);
      }
    }

    navigator.serviceWorker.addEventListener("message", handleSwMessage);
    return () => {
      navigator.serviceWorker.removeEventListener("message", handleSwMessage);
    };
  }, [viewScope]);

  // Refresh all lists when the tab becomes visible again
  useEffect(() => {
    if (!isAuthenticated) return;

    function handleVisibilityChange() {
      if (document.visibilityState !== "visible") return;

      Promise.all([
        fetchItems({ scope: viewScope }),
        delegation
          ? fetchItems({ assigned_to_me: true, scope: viewScope })
          : Promise.resolve([]),
        delegation
          ? fetchItems({ delegated: true, scope: viewScope })
          : Promise.resolve([]),
      ])
        .then(([itemsData, assignedData, delegatedData]) => {
          setItems(itemsData);
          setAssignedItems(assignedData);
          setDelegatedItems(delegatedData);
        })
        .catch(console.error);
    }

    document.addEventListener("visibilitychange", handleVisibilityChange);
    return () => {
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, [isAuthenticated, viewScope, delegation]);

  // Sort my tasks by urgency (only when dates feature is enabled)
  const urgencySorted = dates ? sortByUrgency(items) : items;

  // Apply cognitive phase-aware sorting when confidence is high enough
  const sortedItems =
    cognitivePhaseData && cognitivePhaseData.confidence_score > 0.3
      ? applyCognitiveSort(urgencySorted, cognitivePhaseData.current_phase)
      : urgencySorted;

  // Apply project filter client-side
  const filteredItems = filterProjectId
    ? sortedItems.filter((i) => i.project_id === filterProjectId)
    : sortedItems;
  const filteredAssignedItems = filterProjectId
    ? assignedItems.filter((i) => i.project_id === filterProjectId)
    : assignedItems;
  const filteredDelegatedItems = filterProjectId
    ? delegatedItems.filter((i) => i.project_id === filterProjectId)
    : delegatedItems;

  function onRowChange(itemOrUpdater: Item | ((current: Item) => Item)) {
    const updater = (prev: Item[]) => {
      if (!Array.isArray(prev)) return [];
      if (typeof itemOrUpdater === "function") {
        return prev.map(itemOrUpdater);
      }
      return prev.map((i) => (i.id === itemOrUpdater.id ? itemOrUpdater : i));
    };

    // Update all three arrays — the mapper is by ID so non-matching items are unchanged
    setItems(updater);
    setAssignedItems(updater);
    setDelegatedItems(updater);

    // Re-fetch cognitive phase when a task status changes to done
    if (
      cognitivePhase &&
      typeof itemOrUpdater !== "function" &&
      itemOrUpdater.status === "done"
    ) {
      fetchCognitivePhase().then(setCognitivePhaseData).catch(console.error);
    }
  }

  // Decline (reject) an assigned task — optimistically removes from assigned list
  async function handleDecline(item: Item) {
    const previousAssigned = [...assignedItems];
    setAssignedItems((prev) => prev.filter((i) => i.id !== item.id));

    try {
      await updateItem(item.id, { assignee_id: null });
    } catch (error) {
      console.error("Failed to decline task:", error);
      setAssignedItems(previousAssigned);
      showError("Failed to decline task. Please try again.");
    }
  }

  // Optimistic reordering function that updates UI immediately and persists via bulk API
  function onOptimisticReorder(
    itemId: string,
    direction: "up" | "down" | "top",
  ) {
    setItems((prev) => {
      if (!Array.isArray(prev)) return [];

      const currentIndex = prev.findIndex((item) => item.id === itemId);
      if (currentIndex === -1) return prev;

      const newList = [...prev];
      if (direction === "up" && currentIndex > 0) {
        [newList[currentIndex], newList[currentIndex - 1]] = [
          newList[currentIndex - 1],
          newList[currentIndex],
        ];
      } else if (direction === "down" && currentIndex < newList.length - 1) {
        [newList[currentIndex], newList[currentIndex + 1]] = [
          newList[currentIndex + 1],
          newList[currentIndex],
        ];
      } else if (direction === "top" && currentIndex > 0) {
        const [item] = newList.splice(currentIndex, 1);
        newList.unshift(item);
      } else {
        return prev;
      }

      const ordered = newList.map((item, index) => ({
        ...item,
        position: index,
      }));

      // Persist via single bulk reorder call
      reorderItems(
        ordered.map((item, i) => ({ id: item.id, position: i })),
      ).catch((error) => {
        console.error("Failed to persist reorder:", error);
        setItems(prev);
        showError("Failed to reorder. Changes reverted.");
      });

      return ordered;
    });
  }

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  async function handleCreateProject(name: string): Promise<Project> {
    const newProject = await createProject({ name });
    setProjects((prev) => [...prev, newProject]);
    return newProject;
  }

  function reorderList(
    list: Item[],
    sourceId: string,
    targetId: string,
  ): Item[] {
    const current = [...list];
    const fromIndex = current.findIndex((entry) => entry.id === sourceId);
    const toIndex = current.findIndex((entry) => entry.id === targetId);

    if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
      return current;
    }

    const [moved] = current.splice(fromIndex, 1);
    current.splice(toIndex, 0, moved);

    return current.map((entry, position) => ({ ...entry, position }));
  }

  function handleDragStart(id: string) {
    setDraggingId(id);
    setDragOverId(id);
    dragSourceRef.current = id;
    dragOverRef.current = id;
  }

  function handleDragEnter(id: string) {
    setDragOverId(id);
    dragOverRef.current = id;
  }

  function handleDragEnd() {
    if (dropHandledRef.current) {
      dropHandledRef.current = false;
      setDraggingId(null);
      setDragOverId(null);
      dragSourceRef.current = null;
      dragOverRef.current = null;
      return;
    }

    setDraggingId(null);
    setDragOverId(null);
    dragSourceRef.current = null;
    dragOverRef.current = null;
  }

  function handleDrop(targetId: string) {
    dropHandledRef.current = true;

    setItems((prev) => {
      if (!Array.isArray(prev)) return [];

      const sourceId = draggingId;

      const ordered =
        sourceId && sourceId !== targetId
          ? reorderList(prev, sourceId, targetId)
          : prev.map((entry, position) => ({ ...entry, position }));

      // Persist via single bulk reorder call
      reorderItems(
        ordered.map((item, i) => ({ id: item.id, position: i })),
      ).catch((error) => {
        console.error("Failed to persist reorder:", error);
        setItems(prev);
        showError("Failed to reorder. Changes reverted.");
      });

      return ordered;
    });

    setDraggingId(null);
    setDragOverId(null);
    dragSourceRef.current = null;
    dragOverRef.current = null;
  }

  // Show splash screen while checking auth or loading data
  if (authLoading || (!isAuthenticated && !authLoading) || loading) {
    return <SplashScreen />;
  }

  return (
    <div className="flex flex-col gap-3 pb-24">
      {/* Error toast */}
      {toast && (
        <div className="fixed top-4 inset-x-4 z-30 mx-auto max-w-md animate-fade-in">
          <div className="flex items-center gap-2 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 shadow-lg">
            <span className="flex-1">{toast}</span>
            <button
              type="button"
              onClick={() => setToast(null)}
              className="shrink-0 text-red-400 hover:text-red-600 transition-colors"
              aria-label="Dismiss"
            >
              <svg
                viewBox="0 0 24 24"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
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
      )}

      {/* Header */}
      <header className="sticky top-0 z-10 -mx-4 bg-[var(--page-bg)]/95 px-4 pb-2 pt-3 backdrop-blur">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-semibold text-[var(--navy)]">
            Ballistic
            <br />
            <small>The Simplest Bullet Journal</small>
          </h1>
          <button
            type="button"
            aria-label="Logout"
            onClick={() => {
              if (confirm("Are you sure you want to logout?")) {
                handleLogout();
              }
            }}
            className="tap-target grid h-9 w-9 place-items-center rounded-md bg-white shadow-sm hover:shadow-md active:scale-95"
            title={`Logout ${user?.name || ""}`}
          >
            {/* logout icon */}
            <svg
              viewBox="0 0 24 24"
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              className="text-[var(--navy)]"
            >
              <path
                d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
          </button>
        </div>
      </header>

      {/* Planned view banner */}
      {dates && viewScope === "planned" && (
        <div className="flex items-center justify-between rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-700 border border-sky-200">
          <span>Showing planned items (future scheduled dates)</span>
          <button
            type="button"
            onClick={() => setViewScope("active")}
            className="text-xs font-medium text-sky-600 hover:text-sky-800 underline"
          >
            Back to active
          </button>
        </div>
      )}

      {/* Cognitive Phase Wave */}
      {cognitivePhaseData && cognitivePhaseData.confidence_score > 0 && (
        <CognitiveWave phase={cognitivePhaseData} />
      )}

      {/* List */}
      <div className="flex flex-col gap-2">
        {/* Render a single item as a row */}
        {(() => {
          function renderItem(item: Item, index: number) {
            return (
              <ItemRow
                key={item.id || `item-${index}`}
                item={item}
                onChange={onRowChange}
                onOptimisticReorder={onOptimisticReorder}
                index={index}
                onEdit={() => {
                  setEditingItem(item);
                  setShowEditModal(true);
                }}
                isFirst={index === 0}
                onDragStart={handleDragStart}
                onDragEnter={handleDragEnter}
                onDropItem={handleDrop}
                onDragEnd={handleDragEnd}
                draggingId={draggingId}
                dragOverId={dragOverId}
                onError={showError}
              />
            );
          }

          return (
            <>
              {/* Assigned to Me section */}
              {delegation && filteredAssignedItems.length > 0 && (
                <>
                  <div className="flex items-center justify-between mt-2">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-emerald-600">
                      Assigned to Me
                    </h2>
                  </div>
                  {filteredAssignedItems.map((item, index) => (
                    <div key={item.id || `assigned-${index}`}>
                      {renderItem(item, index)}
                      <button
                        type="button"
                        onClick={() => handleDecline(item)}
                        className="mt-1 text-xs text-red-500 hover:text-red-700 transition-colors px-3"
                      >
                        Decline
                      </button>
                    </div>
                  ))}
                </>
              )}

              {/* My Tasks section */}
              {filteredItems.length > 0 && (
                <>
                  {delegation && (
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-[var(--navy)] mt-2">
                      My Tasks
                    </h2>
                  )}
                  {filteredItems.map((item, index) => renderItem(item, index))}
                </>
              )}

              {/* Delegated to Others section */}
              {delegation && filteredDelegatedItems.length > 0 && (
                <>
                  <h2 className="text-xs font-semibold uppercase tracking-wider text-amber-600 mt-2">
                    Delegated to Others
                  </h2>
                  {filteredDelegatedItems.map((item, index) =>
                    renderItem(item, index),
                  )}
                </>
              )}

              {/* Empty state */}
              {filteredItems.length === 0 &&
                (!delegation ||
                  (filteredAssignedItems.length === 0 &&
                    filteredDelegatedItems.length === 0)) &&
                !loading && (
                  <EmptyState
                    type="no-items"
                    message={
                      filterProjectId
                        ? "No tasks in this project."
                        : "Start your bullet journal journey by adding your first task!"
                    }
                  />
                )}
            </>
          );
        })()}
      </div>

      {/* Footer */}
      <footer className="text-center py-6 text-sm text-slate-500">
        Psycode Pty. Ltd. © {new Date().getFullYear()}
      </footer>

      {/* Filter Panel */}
      {showFilter && (
        <>
          <div
            className="fixed inset-0 z-[19]"
            onClick={() => setShowFilter(false)}
            aria-hidden="true"
          />
          <div className="fixed inset-x-0 bottom-[4.5rem] z-[21]">
            <div className="mx-auto max-w-sm px-4">
              <div className="rounded-xl bg-white shadow-xl border border-slate-200/50 p-4 space-y-4 animate-slide-in-up">
                {/* Project filter */}
                <div>
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">
                    Project
                  </h3>
                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      onClick={() => setFilterProjectId(null)}
                      className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                        filterProjectId === null
                          ? "bg-[var(--blue)] text-white"
                          : "bg-slate-100 text-slate-700 hover:bg-slate-200"
                      }`}
                    >
                      All
                    </button>
                    {projects.map((project) => (
                      <button
                        key={project.id}
                        type="button"
                        onClick={() => setFilterProjectId(project.id)}
                        className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                          filterProjectId === project.id
                            ? "bg-[var(--blue)] text-white"
                            : "bg-slate-100 text-slate-700 hover:bg-slate-200"
                        }`}
                      >
                        {project.name}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Scope toggle (dates feature only) */}
                {dates && (
                  <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">
                      Scope
                    </h3>
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={() => setViewScope("active")}
                        className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                          viewScope === "active"
                            ? "bg-[var(--blue)] text-white"
                            : "bg-slate-100 text-slate-700 hover:bg-slate-200"
                        }`}
                      >
                        Active
                      </button>
                      <button
                        type="button"
                        onClick={() => setViewScope("planned")}
                        className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                          viewScope === "planned"
                            ? "bg-[var(--blue)] text-white"
                            : "bg-slate-100 text-slate-700 hover:bg-slate-200"
                        }`}
                      >
                        Planned
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </>
      )}

      {/* Bottom Bar - Glassy style matching top bar */}
      <div className="fixed inset-x-0 bottom-0 z-20 bg-[var(--page-bg)]/95 backdrop-blur border-t border-slate-200/50">
        <div className="mx-auto grid max-w-sm grid-cols-[1fr_auto_1fr] items-center px-4 py-3">
          <div className="flex items-center gap-1">
            <button
              type="button"
              aria-label="Settings"
              onClick={() => setShowSettings(true)}
              className="tap-target grid h-10 w-10 place-items-center rounded-md hover:bg-slate-100 active:scale-95 transition-all duration-200"
            >
              {/* gear icon */}
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className="text-[var(--navy)]"
              >
                <path
                  d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.4-3.5a7.4 7.4 0 0 0-.1-1l2.1-1.6-2-3.4-2.5 1a7.6 7.6 0 0 0-1.7-1l-.4-2.6H9.2L8.8 6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.4 2.1 1.6a7.4 7.4 0 0 0 0 2L2.6 14l2 3.4 2.5-1a7.6 7.6 0 0 0 1.7 1l.4 2.6h5.6l.4-2.6a7.6 7.6 0 0 0 1.7-1l2.5 1 2-3.4-2.1-1.6c.1-.3.1-.7.1-1Z"
                  strokeWidth="1.4"
                />
              </svg>
            </button>
            <button
              type="button"
              aria-label="Notes"
              onClick={() => setShowNotes(true)}
              className="tap-target grid h-10 w-10 place-items-center rounded-md hover:bg-slate-100 active:scale-95 transition-all duration-200"
            >
              {/* notepad icon */}
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className="text-[var(--navy)]"
              >
                <path
                  d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"
                  strokeWidth="1.4"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
                <path
                  d="M14 2v6h6M16 13H8M16 17H8M10 9H8"
                  strokeWidth="1.4"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </button>
          </div>
          <button
            type="button"
            aria-label="Add a new task"
            onClick={() => {
              setEditingItem(null);
              setShowEditModal(true);
            }}
            className="tap-target grid h-12 w-12 place-items-center rounded-full bg-[var(--blue)] text-white shadow-md hover:shadow-lg hover:bg-[var(--blue-600)] active:scale-95 transition-all duration-200"
          >
            <span className="sr-only">Add new task...</span>
            <span className="text-2xl leading-none font-light">+</span>
          </button>
          <div className="flex justify-end">
            <button
              type="button"
              aria-label="Filter"
              onClick={() => setShowFilter((prev) => !prev)}
              className={`tap-target grid h-11 w-11 place-items-center rounded-full shadow-sm hover:shadow-md active:scale-95 ${showFilter || filterProjectId !== null || (dates && viewScope === "planned") ? "bg-[var(--blue)]" : "bg-white"}`}
            >
              {/* funnel icon */}
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className={
                  showFilter ||
                  filterProjectId !== null ||
                  (dates && viewScope === "planned")
                    ? "text-white"
                    : "text-[var(--navy)]"
                }
              >
                <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z" strokeWidth="1.5" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      {/* Settings Modal */}
      <SettingsModal
        isOpen={showSettings}
        onClose={() => setShowSettings(false)}
      />

      {/* Notes Modal */}
      <NotesModal isOpen={showNotes} onClose={() => setShowNotes(false)} />

      {/* Edit/Create Item Modal */}
      <EditItemModal
        isOpen={showEditModal}
        onClose={() => {
          setShowEditModal(false);
          setEditingItem(null);
        }}
        item={editingItem}
        projects={projects}
        onCreateProject={handleCreateProject}
        showAssignment={delegation}
        favourites={user?.favourites}
        onFavouriteToggled={refreshUser}
        onSubmit={async (v) => {
          if (editingItem) {
            // Update existing item
            const selectedProject = v.project_id
              ? projects.find((p) => p.id === v.project_id)
              : null;
            const optimisticUpdate: Item = {
              ...editingItem,
              title: v.title,
              description: v.description || null,
              cognitive_load_score:
                v.cognitive_load_score ?? editingItem.cognitive_load_score,
              assignee_notes:
                v.assignee_notes !== undefined
                  ? v.assignee_notes
                  : editingItem.assignee_notes,
              project_id: v.project_id ?? null,
              project: selectedProject ?? null,
              scheduled_date: v.scheduled_date ?? null,
              due_date: v.due_date ?? null,
              recurrence_rule: v.recurrence_rule ?? null,
              recurrence_strategy:
                (v.recurrence_strategy as Item["recurrence_strategy"]) ?? null,
              is_recurring_template: !!v.recurrence_rule,
              assignee_id: v.assignee_id ?? null,
            };

            // Update all arrays optimistically
            const updater = (prev: Item[]) =>
              prev.map((i) => (i.id === editingItem.id ? optimisticUpdate : i));
            setItems(updater);
            setAssignedItems(updater);
            setDelegatedItems(updater);

            updateItem(editingItem.id, {
              title: v.title,
              description: v.description || null,
              assignee_notes: v.assignee_notes,
              project_id: v.project_id,
              scheduled_date: v.scheduled_date,
              due_date: v.due_date,
              recurrence_rule: v.recurrence_rule,
              recurrence_strategy:
                (v.recurrence_strategy as Item["recurrence_strategy"]) ?? null,
              assignee_id: v.assignee_id,
              cognitive_load_score: v.cognitive_load_score,
            }).catch((error) => {
              console.error("Failed to update item:", error);
              const revert = (prev: Item[]) =>
                prev.map((i) => (i.id === editingItem.id ? editingItem : i));
              setItems(revert);
              setAssignedItems(revert);
              setDelegatedItems(revert);
              showError("Failed to update task. Changes reverted.");
            });
          } else {
            // Create new item
            const tempId = `temp-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
            const selectedProject = v.project_id
              ? projects.find((p) => p.id === v.project_id)
              : null;
            const optimisticItem: Item = {
              id: tempId,
              user_id: user?.id || "",
              assignee_id: v.assignee_id ?? null,
              project_id: v.project_id ?? null,
              title: v.title,
              description: v.description || null,
              status: "todo",
              position: items.length,
              cognitive_load_score: v.cognitive_load_score ?? null,
              scheduled_date: v.scheduled_date ?? null,
              due_date: v.due_date ?? null,
              completed_at: null,
              recurrence_rule: v.recurrence_rule ?? null,
              recurrence_parent_id: null,
              recurrence_strategy:
                (v.recurrence_strategy as Item["recurrence_strategy"]) ?? null,
              is_recurring_template: !!v.recurrence_rule,
              is_recurring_instance: false,
              assignee_notes: null,
              is_assigned: !!v.assignee_id,
              is_delegated: !!v.assignee_id,
              created_at: new Date().toISOString(),
              updated_at: new Date().toISOString(),
              deleted_at: null,
              project: selectedProject ?? null,
            };

            // Update UI immediately
            setItems((prev) => {
              if (!Array.isArray(prev)) {
                return [optimisticItem];
              }
              const hasDuplicate = prev.some((item) => item.id === tempId);
              if (hasDuplicate) {
                console.warn("Duplicate ID detected, skipping optimistic item");
                return prev;
              }
              return [...prev, optimisticItem];
            });

            // Set the item to scroll to
            setScrollToItemId(tempId);

            // Send API request in background
            createItem({
              title: v.title,
              description: v.description,
              status: "todo",
              project_id: v.project_id,
              position: items.length,
              scheduled_date: v.scheduled_date,
              due_date: v.due_date,
              recurrence_rule: v.recurrence_rule,
              recurrence_strategy: v.recurrence_strategy,
              assignee_id: v.assignee_id,
              cognitive_load_score: v.cognitive_load_score,
            })
              .then((created) => {
                const resolvedItem = normaliseItemResponse(created);
                setItems((prev) => {
                  if (!Array.isArray(prev)) {
                    return [resolvedItem];
                  }
                  return prev.map((item) =>
                    item.id === tempId ? resolvedItem : item,
                  );
                });
              })
              .catch((error) => {
                console.error("Failed to create item:", error);
                setItems((prev) => prev.filter((item) => item.id !== tempId));
                showError("Failed to create task. Please try again.");
              });
          }
        }}
      />
    </div>
  );
}
