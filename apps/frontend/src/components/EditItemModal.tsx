"use client";

import { useCallback, useEffect, useRef } from "react";
import type { Item, Project, UserLookup } from "@/types";
import { ItemForm } from "./ItemForm";

interface EditItemModalProps {
  isOpen: boolean;
  onClose: () => void;
  item: Item | null; // null for new items
  projects: Project[];
  onCreateProject: (name: string) => Promise<Project>;
  onSubmit: (values: {
    title: string;
    description?: string;
    project_id?: string | null;
    scheduled_date?: string | null;
    due_date?: string | null;
    recurrence_rule?: string | null;
    recurrence_strategy?: string | null;
    assignee_id?: string | null;
    assignee_notes?: string | null;
    cognitive_load_score?: number | null;
  }) => void;
  showAssignment?: boolean;
  favourites?: UserLookup[];
  onFavouriteToggled?: () => Promise<void>;
}

/**
 * Full-screen modal for creating and editing tasks.
 */
export function EditItemModal({
  isOpen,
  onClose,
  item,
  projects,
  onCreateProject,
  onSubmit,
  showAssignment = true,
  favourites,
  onFavouriteToggled,
}: EditItemModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);

  // Handle submission - call onSubmit then close
  const handleSubmit = useCallback(
    (values: {
      title: string;
      description?: string;
      project_id?: string | null;
      scheduled_date?: string | null;
      due_date?: string | null;
      recurrence_rule?: string | null;
      recurrence_strategy?: string | null;
      assignee_id?: string | null;
      assignee_notes?: string | null;
      cognitive_load_score?: number | null;
    }) => {
      onSubmit(values);
      onClose();
    },
    [onSubmit, onClose],
  );

  // Close on escape key
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") {
        onClose();
      }
    }

    if (isOpen) {
      document.addEventListener("keydown", handleKeyDown);
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
  }, [isOpen, onClose]);

  // Close on click outside
  function handleBackdropClick(e: React.MouseEvent) {
    if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
      onClose();
    }
  }

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm animate-fade-in"
      onClick={handleBackdropClick}
    >
      <div
        ref={modalRef}
        className="w-[90vw] max-w-2xl max-h-[90vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl animate-scale-in"
      >
        {/* Header */}
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {item ? "Edit Task" : "New Task"}
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

        {/* ItemForm */}
        <ItemForm
          initial={item ?? undefined}
          onSubmit={handleSubmit}
          onCancel={onClose}
          submitLabel={item ? "Save" : "Add"}
          projects={projects}
          onCreateProject={onCreateProject}
          showAssignment={showAssignment}
          favourites={favourites}
          onFavouriteToggled={onFavouriteToggled}
        />
      </div>
    </div>
  );
}
