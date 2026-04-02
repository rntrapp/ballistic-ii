"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { FocusTrap } from "focus-trap-react";
import { fetchUser, updateUser } from "@/lib/api";
import { useModal } from "@/hooks/useModal";

interface NotesModalProps {
  isOpen: boolean;
  onClose: () => void;
}

const MAX_CHARS = 10_000;

/**
 * Full-screen modal providing a free-text scratchpad persisted on the backend.
 */
export function NotesModal({ isOpen, onClose }: NotesModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  useModal(isOpen);
  const [notes, setNotes] = useState("");
  const [originalNotes, setOriginalNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const savedTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Load notes fresh on open
  useEffect(() => {
    if (isOpen) {
      fetchUser()
        .then((user) => {
          const value = user.notes ?? "";
          setNotes(value);
          setOriginalNotes(value);
          setSaved(false);
        })
        .catch((err) => console.error("Failed to load notes:", err));
    }
  }, [isOpen]);

  const saveNotes = useCallback(
    async (value: string) => {
      if (value === originalNotes) return;
      setSaving(true);
      setSaved(false);
      try {
        await updateUser({ notes: value || null });
        setOriginalNotes(value);
        setSaved(true);
        if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
        savedTimerRef.current = setTimeout(() => setSaved(false), 2000);
      } catch (err) {
        console.error("Failed to save notes:", err);
      } finally {
        setSaving(false);
      }
    },
    [originalNotes],
  );

  // Save on close if changed
  const handleClose = useCallback(() => {
    if (notes !== originalNotes) {
      saveNotes(notes);
    }
    onClose();
  }, [notes, originalNotes, saveNotes, onClose]);

  // Close on escape key
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") {
        handleClose();
      }
    }

    if (isOpen) {
      document.addEventListener("keydown", handleKeyDown);
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
  }, [isOpen, handleClose]);

  // Close on click outside
  function handleBackdropClick(e: React.MouseEvent) {
    if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
      handleClose();
    }
  }

  // Cleanup timer on unmount
  useEffect(() => {
    return () => {
      if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
    };
  }, []);

  if (!isOpen) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm animate-fade-in"
      onClick={handleBackdropClick}
    >
      <FocusTrap focusTrapOptions={{ allowOutsideClick: true }}>
        <div
          ref={modalRef}
          className="w-[90vw] max-w-2xl max-h-[90vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl animate-scale-in"
        >
          {/* Header */}
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-gray-900">Notes</h2>
            <div className="flex items-center gap-3">
              {/* Save indicator */}
              {saving && (
                <span className="text-xs text-gray-400">Saving...</span>
              )}
              {saved && !saving && (
                <span className="text-xs text-green-500">Saved</span>
              )}
              <button
                type="button"
                onClick={handleClose}
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
          </div>

          {/* Textarea */}
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value.slice(0, MAX_CHARS))}
            onBlur={() => saveNotes(notes)}
            placeholder="Jot down anything..."
            className="font-reading w-full min-h-[400px] max-h-[80vh] resize-y rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-300 focus:outline-none focus:ring-1 focus:ring-blue-300"
          />

          {/* Character counter */}
          <div className="mt-2 text-right text-xs text-gray-400">
            {notes.length.toLocaleString()} / {MAX_CHARS.toLocaleString()}
          </div>
        </div>
      </FocusTrap>
    </div>
  );
}
