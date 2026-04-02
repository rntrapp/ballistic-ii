"use client";

import { useEffect, useRef, useState } from "react";
import { FocusTrap } from "focus-trap-react";
import { PushNotificationToggle } from "./PushNotificationToggle";
import { useFeatureFlags } from "@/hooks/useFeatureFlags";
import { useModal } from "@/hooks/useModal";

interface SettingsModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export function SettingsModal({ isOpen, onClose }: SettingsModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  const { dates, delegation, userFlags, available, setFlag } =
    useFeatureFlags();
  useModal(isOpen);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  async function handleToggle(flag: "dates" | "delegation", value: boolean) {
    if (saving) return;

    setSaving(true);
    setError(null);

    try {
      await setFlag(flag, value);
    } catch (err) {
      console.error("Failed to save feature flag:", err);
      setError(
        err instanceof Error && err.message
          ? err.message
          : "Failed to save settings. Please try again.",
      );
    } finally {
      setSaving(false);
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
          <div className="mb-6 flex items-center justify-between">
            <h2 className="text-lg font-semibold text-gray-900">Settings</h2>
            <button
              type="button"
              onClick={onClose}
              className="rounded-full p-2 transition-colors hover:bg-gray-100"
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

          {error && (
            <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
              {error}
            </div>
          )}

          {saving && (
            <div className="mb-4 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700">
              Saving...
            </div>
          )}

          <div className="space-y-6">
            <section>
              <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-500">
                Features
              </h3>
              <div className="space-y-4 rounded-lg bg-gray-50 p-4">
                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    onClick={() => handleToggle("dates", !userFlags.dates)}
                    disabled={saving || !available.dates}
                    className={`
                      relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full
                      border-2 border-transparent transition-colors duration-200 ease-in-out
                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
                      ${userFlags.dates && available.dates ? "bg-blue-600" : "bg-gray-200"}
                      ${saving || !available.dates ? "cursor-not-allowed opacity-50" : ""}
                    `}
                    role="switch"
                    aria-checked={dates}
                    aria-label="Dates & Scheduling"
                  >
                    <span
                      className={`
                        pointer-events-none inline-block h-5 w-5 transform rounded-full
                        bg-white shadow ring-0 transition duration-200 ease-in-out
                        ${userFlags.dates && available.dates ? "translate-x-5" : "translate-x-0"}
                      `}
                    />
                  </button>
                  <div className="flex flex-col">
                    <span className="text-sm font-medium text-gray-900">
                      Dates &amp; Scheduling
                    </span>
                    <span className="text-xs text-gray-500">
                      {available.dates
                        ? "Due dates, scheduled dates, and repeating tasks"
                        : "Coming soon"}
                    </span>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    onClick={() =>
                      handleToggle("delegation", !userFlags.delegation)
                    }
                    disabled={saving || !available.delegation}
                    className={`
                      relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full
                      border-2 border-transparent transition-colors duration-200 ease-in-out
                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
                      ${userFlags.delegation && available.delegation ? "bg-blue-600" : "bg-gray-200"}
                      ${saving || !available.delegation ? "cursor-not-allowed opacity-50" : ""}
                    `}
                    role="switch"
                    aria-checked={delegation}
                    aria-label="Task Delegation"
                  >
                    <span
                      className={`
                        pointer-events-none inline-block h-5 w-5 transform rounded-full
                        bg-white shadow ring-0 transition duration-200 ease-in-out
                        ${userFlags.delegation && available.delegation ? "translate-x-5" : "translate-x-0"}
                      `}
                    />
                  </button>
                  <div className="flex flex-col">
                    <span className="text-sm font-medium text-gray-900">
                      Task Delegation
                    </span>
                    <span className="text-xs text-gray-500">
                      {available.delegation
                        ? "Assign tasks to other users"
                        : "Coming soon"}
                    </span>
                  </div>
                </div>
              </div>
            </section>

            <section>
              <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-500">
                Notifications
              </h3>
              <div className="rounded-lg bg-gray-50 p-4">
                <PushNotificationToggle />
              </div>
            </section>

            <section className="border-t border-gray-100 pt-4">
              <p className="text-center text-xs text-gray-400">
                Ballistic v0.17.1
              </p>
            </section>
          </div>
        </div>
      </FocusTrap>
    </div>
  );
}
