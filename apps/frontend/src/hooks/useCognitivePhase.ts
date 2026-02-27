"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { CognitiveEventPoint, CognitivePhaseSnapshot } from "@/types";
import { fetchCognitiveEvents, fetchCognitivePhase } from "@/lib/api";

/** How often to refresh the phase snapshot + event dots (5 min). */
const REFRESH_INTERVAL_MS = 5 * 60 * 1000;

interface UseCognitivePhaseResult {
  snapshot: CognitivePhaseSnapshot | null;
  events: CognitiveEventPoint[];
  isLoading: boolean;
  refresh: () => Promise<void>;
  /**
   * Optimistically add a completion dot to the wave without waiting for the
   * server round-trip. Call immediately when the user marks a task as done
   * so the dot appears on the wave instantly. The next `refresh()` will
   * reconcile with server state.
   */
  addOptimisticEvent: (event: CognitiveEventPoint) => void;
}

/**
 * Hook managing the user's cognitive-phase snapshot and today's completion
 * events for the chronobiology wave. Fetches on mount when the feature flag
 * is enabled, then refreshes on an interval. Exposes an optimistic-insert
 * helper for instant dot plotting on task completion.
 *
 * The `enabled` flag gates network activity entirely — when false, nothing
 * is fetched and all state remains at its initial empty values.
 */
export function useCognitivePhase(enabled: boolean): UseCognitivePhaseResult {
  const [snapshot, setSnapshot] = useState<CognitivePhaseSnapshot | null>(null);
  const [events, setEvents] = useState<CognitiveEventPoint[]>([]);
  const [isLoading, setIsLoading] = useState(false);

  // Track mount status so interval callbacks don't set state after unmount.
  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const refresh = useCallback(async () => {
    if (!enabled) return;
    setIsLoading(true);
    try {
      const [snap, evts] = await Promise.all([
        fetchCognitivePhase(),
        fetchCognitiveEvents(),
      ]);
      if (!mountedRef.current) return;
      setSnapshot(snap);
      setEvents(evts);
    } catch (err) {
      // Don't surface — feature is supplementary. Missing data is a
      // valid state (insufficient samples) and has_profile=false handles it.
      console.error("Cognitive phase fetch failed:", err);
    } finally {
      if (mountedRef.current) setIsLoading(false);
    }
  }, [enabled]);

  // Initial fetch + interval refresh when enabled.
  useEffect(() => {
    if (!enabled) {
      // Feature toggled off — clear any stale data.
      setSnapshot(null);
      setEvents([]);
      return;
    }

    void refresh();

    const id = setInterval(() => {
      void refresh();
    }, REFRESH_INTERVAL_MS);

    return () => clearInterval(id);
  }, [enabled, refresh]);

  const addOptimisticEvent = useCallback((event: CognitiveEventPoint) => {
    setEvents((prev) => [...prev, event]);
  }, []);

  return { snapshot, events, isLoading, refresh, addOptimisticEvent };
}
