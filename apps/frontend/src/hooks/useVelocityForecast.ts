"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { Item, VelocityForecast } from "@/types";
import { fetchVelocityForecast } from "@/lib/api";
import { composeLiveForecast, sumUpcomingEffort } from "@/lib/forecast";

interface UseVelocityForecastReturn {
  forecast: VelocityForecast | null;
  loading: boolean;
  /**
   * Re-fetch the server forecast. Only needed after mutations that change
   * *historical* velocity — i.e. marking an item `done`. Effort/due-date/create
   * mutations are handled reactively by the delta overlay and need no refetch.
   */
  refresh: () => Promise<void>;
}

/**
 * Reactive velocity forecast.
 *
 * Architecture: the server supplies the *historical* half (EMA, σ, weekly
 * series) which requires 12 weeks of completion data the client does not hold.
 * The *upcoming* half (effort due in the next 7 days → burnout, probability)
 * is re-derived client-side on every `items` change via a delta overlay, so an
 * effort 1→8 edit shifts the dashboard on the very same render as the
 * optimistic `setItems` — no server round-trip.
 *
 * Delta = (current client sum of visible upcoming effort) − (that same sum
 * snapshotted at the instant the server forecast resolved). The server's
 * `upcoming_effort` remains the authoritative baseline, so items outside the
 * current view scope still count; the delta only tracks *changes the user can
 * see and has made since the last fetch*.
 */
export function useVelocityForecast(
  enabled: boolean,
  items: readonly Item[],
): UseVelocityForecastReturn {
  const [serverForecast, setServerForecast] = useState<VelocityForecast | null>(
    null,
  );
  const [loading, setLoading] = useState(false);

  // Snapshot of sumUpcomingEffort(items) captured when the server forecast
  // resolved. Stored as state (not a ref) so re-baselining triggers a
  // recomputation of the live forecast memo.
  const [baseline, setBaseline] = useState(0);

  // Ref mirror so `refresh()` can read the *current* items at resolve-time
  // without taking `items` as a dependency (which would cause a refetch on
  // every keystroke).
  const itemsRef = useRef(items);
  itemsRef.current = items;

  const refresh = useCallback(async () => {
    if (!enabled) return;
    setLoading(true);
    try {
      const fetched = await fetchVelocityForecast();
      // Baseline is the client's view of upcoming effort at the precise
      // moment the server's number was produced. Any subsequent divergence
      // is the reactive delta.
      setBaseline(sumUpcomingEffort(itemsRef.current));
      setServerForecast(fetched);
    } catch (error) {
      console.error("Failed to fetch velocity forecast:", error);
    } finally {
      setLoading(false);
    }
  }, [enabled]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const clientUpcoming = useMemo(() => sumUpcomingEffort(items), [items]);

  const forecast = useMemo(() => {
    if (!serverForecast) return null;
    const delta = clientUpcoming - baseline;
    if (delta === 0) return serverForecast;
    const liveUpcoming = Math.max(0, serverForecast.upcoming_effort + delta);
    return composeLiveForecast(serverForecast, liveUpcoming);
  }, [serverForecast, clientUpcoming, baseline]);

  return { forecast, loading, refresh };
}
