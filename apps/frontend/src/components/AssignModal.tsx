"use client";

import type { UserLookup } from "@/types";
import { useState, useEffect, useCallback } from "react";
import { FocusTrap } from "focus-trap-react";
import { lookupUsers, discoverUser, toggleFavourite } from "@/lib/api";
import { useModal } from "@/hooks/useModal";

type Props = {
  isOpen: boolean;
  onClose: () => void;
  onSelect: (user: UserLookup | null) => void;
  currentAssignee?: UserLookup | null;
  favourites?: UserLookup[];
  onFavouriteToggled?: () => Promise<void>;
};

export function AssignModal({
  isOpen,
  onClose,
  onSelect,
  currentAssignee,
  favourites = [],
  onFavouriteToggled,
}: Props) {
  useModal(isOpen);
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<UserLookup[]>([]);
  const [discoveredUser, setDiscoveredUser] = useState<UserLookup | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [togglingFavId, setTogglingFavId] = useState<string | null>(null);

  // Debounced search: lookup connected users first, then discover if empty
  const searchUsers = useCallback(async (searchQuery: string) => {
    if (searchQuery.length < 3) {
      setResults([]);
      setDiscoveredUser(null);
      return;
    }

    setLoading(true);
    setError(null);
    setDiscoveredUser(null);

    try {
      // First search among connected users
      const users = await lookupUsers(searchQuery);

      if (users.length > 0) {
        setResults(users);
        return;
      }

      // No connected user found — try discovery (exact email or phone)
      setResults([]);
      const discovery = await discoverUser(searchQuery);
      if (discovery.found && discovery.user) {
        setDiscoveredUser(discovery.user);
      }
    } catch (err) {
      setError("Failed to search users");
      console.error("User lookup error:", err);
    } finally {
      setLoading(false);
    }
  }, []);

  // Debounce the search
  useEffect(() => {
    const timer = setTimeout(() => {
      searchUsers(query);
    }, 300);

    return () => clearTimeout(timer);
  }, [query, searchUsers]);

  // Reset state when modal closes
  useEffect(() => {
    if (!isOpen) {
      setQuery("");
      setResults([]);
      setDiscoveredUser(null);
      setError(null);
    }
  }, [isOpen]);

  async function handleToggleFavourite(
    e: React.MouseEvent,
    userId: string,
  ): Promise<void> {
    e.stopPropagation();
    setTogglingFavId(userId);
    try {
      await toggleFavourite(userId);
      await onFavouriteToggled?.();
    } catch (err) {
      console.error("Failed to toggle favourite:", err);
    } finally {
      setTogglingFavId(null);
    }
  }

  function isFavourite(userId: string): boolean {
    return favourites.some((f) => f.id === userId);
  }

  if (!isOpen) return null;

  const showFavourites = query.length < 3 && favourites.length > 0;

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 animate-fade-in"
      onClick={onClose}
    >
      <FocusTrap focusTrapOptions={{ allowOutsideClick: true }}>
        <div
          className="w-full max-w-md mx-4 bg-white rounded-lg shadow-xl animate-scale-in"
          onClick={(e) => e.stopPropagation()}
        >
          {/* Header */}
          <div className="flex items-center justify-between p-4 border-b border-slate-200">
            <h2 className="text-lg font-semibold text-[var(--navy)]">
              Assign Task
            </h2>
            <button
              onClick={onClose}
              className="p-1 rounded-md hover:bg-slate-100 transition-colors"
              aria-label="Close"
            >
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className="text-slate-500"
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

          {/* Search input */}
          <div className="p-4">
            <input
              type="text"
              placeholder="Search by email or phone..."
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm transition-all duration-200 focus:border-[var(--blue)] focus:ring-2 focus:ring-[var(--blue)]/20 focus:outline-none"
              autoFocus
            />
            <p className="mt-2 text-xs text-slate-500">
              Enter email or last 9 digits of phone number
            </p>
          </div>

          {/* Current assignee */}
          {currentAssignee && (
            <div className="px-4 pb-2">
              <div className="flex items-center justify-between p-3 bg-slate-50 rounded-md">
                <div>
                  <div className="text-sm font-medium text-slate-700">
                    {currentAssignee.name}
                  </div>
                  <div className="text-xs text-slate-500">
                    {currentAssignee.email_masked}
                  </div>
                </div>
                <button
                  onClick={() => onSelect(null)}
                  className="px-3 py-1 text-sm text-red-600 bg-red-50 rounded-md hover:bg-red-100 transition-colors"
                >
                  Remove
                </button>
              </div>
            </div>
          )}

          {/* Favourites quick-pick (shown when no active search) */}
          {showFavourites && (
            <div className="px-4 pb-2">
              <p className="text-xs font-medium text-slate-500 mb-2">
                Favourites
              </p>
              <div className="flex flex-wrap gap-2">
                {favourites.map((fav) => (
                  <button
                    key={fav.id}
                    onClick={() => {
                      onSelect(fav);
                      onClose();
                    }}
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-full text-sm text-slate-700 hover:bg-amber-100 transition-colors"
                  >
                    <span className="text-amber-500">★</span>
                    {fav.name}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Results */}
          <div className="px-4 pb-4 max-h-64 overflow-y-auto">
            {loading && (
              <div className="py-4 text-center text-sm text-slate-500">
                Searching...
              </div>
            )}

            {error && (
              <div className="py-4 text-center text-sm text-red-500">
                {error}
              </div>
            )}

            {!loading &&
              !error &&
              results.length === 0 &&
              !discoveredUser &&
              query.length >= 3 && (
                <div className="py-4 text-center text-sm text-slate-500">
                  No users found
                </div>
              )}

            {/* Connected users — direct selection */}
            {!loading && results.length > 0 && (
              <div className="space-y-2">
                {results.map((resultUser) => (
                  <button
                    key={resultUser.id}
                    onClick={() => {
                      onSelect(resultUser);
                      onClose();
                    }}
                    className="w-full flex items-center gap-3 p-3 rounded-md hover:bg-slate-50 transition-colors text-left"
                  >
                    <div className="w-10 h-10 rounded-full bg-[var(--blue)] flex items-center justify-center text-white font-medium">
                      {resultUser.name.charAt(0).toUpperCase()}
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-1">
                        <span className="text-sm font-medium text-slate-700">
                          {resultUser.name}
                        </span>
                        {isFavourite(resultUser.id) && (
                          <span className="text-amber-500 text-xs">★</span>
                        )}
                      </div>
                      <div className="text-xs text-slate-500">
                        {resultUser.email_masked}
                      </div>
                    </div>
                    <button
                      type="button"
                      aria-label={
                        isFavourite(resultUser.id)
                          ? "Remove from favourites"
                          : "Add to favourites"
                      }
                      disabled={togglingFavId === resultUser.id}
                      onClick={(e) => handleToggleFavourite(e, resultUser.id)}
                      className="p-1 rounded-md hover:bg-slate-100 transition-colors disabled:opacity-50"
                    >
                      <span
                        className={`text-lg ${isFavourite(resultUser.id) ? "text-amber-400" : "text-slate-300"}`}
                      >
                        ★
                      </span>
                    </button>
                  </button>
                ))}
              </div>
            )}

            {/* Discovered user — not yet connected, but backend auto-connects on assignment */}
            {!loading && discoveredUser && (
              <div className="space-y-2">
                <p className="text-xs text-slate-500">
                  User found — a connection will be created automatically
                </p>
                <button
                  onClick={() => {
                    onSelect(discoveredUser);
                    onClose();
                  }}
                  className="w-full flex items-center gap-3 p-3 rounded-md bg-amber-50 border border-amber-200 hover:bg-amber-100 transition-colors text-left"
                >
                  <div className="w-10 h-10 rounded-full bg-amber-500 flex items-center justify-center text-white font-medium">
                    {discoveredUser.name.charAt(0).toUpperCase()}
                  </div>
                  <div>
                    <div className="text-sm font-medium text-slate-700">
                      {discoveredUser.name}
                    </div>
                    <div className="text-xs text-slate-500">
                      {discoveredUser.email_masked}
                    </div>
                  </div>
                </button>
              </div>
            )}
          </div>
        </div>
      </FocusTrap>
    </div>
  );
}
