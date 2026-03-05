"use client";

import { useEffect, useMemo, useState } from "react";
import type { Item, VelocityForecast } from "@/types";
import { fetchVelocity } from "@/lib/api";

type Props = {
  /** All items visible to the current user (my tasks + assigned to me). */
  items: Item[];
  lookbackWeeks?: number;
};

/** Statuses that count toward upcoming workload (open work only). */
const OPEN_STATUSES: ReadonlySet<string> = new Set(["todo", "doing"]);

// ─────────────────────────────────────────────────────────────────────────────
// Fixed-point arithmetic (BigInt, scale=6) mirroring backend BCMath.
// The server computes velocity with BCMath at 6 decimal places specifically
// to avoid IEEE-754 drift near the burnout threshold. Parsing that string
// into a JS float (`Number("10.000000")`) would reintroduce exactly the drift
// we paid to eliminate. Instead we treat every quantity as an integer number
// of micro-units (×10⁶) and use native BigInt for exact arithmetic.
// ─────────────────────────────────────────────────────────────────────────────

const SCALE = 6;
const ZERO = BigInt(0);
const ONE = BigInt(1);
const NEG_ONE = BigInt(-1);
export const UNIT: bigint = BigInt(10) ** BigInt(SCALE); // 1_000_000
const PCT_50 = BigInt(500_000);
const PCT_80 = BigInt(800_000);

/** Parse a BCMath decimal string (e.g. "10.000000", "0.3", "7") into micro-units. */
export function decimalToMicros(s: string): bigint {
  const [intPart, fracPart = ""] = s.split(".");
  const frac = (fracPart + "0".repeat(SCALE)).slice(0, SCALE);
  const sign = intPart.startsWith("-") ? NEG_ONE : ONE;
  const absInt = intPart.replace(/^[-+]/, "") || "0";
  return sign * (BigInt(absInt) * UNIT + BigInt(frac));
}

/** Lift a JS integer into micro-units. */
export function intToMicros(n: number): bigint {
  return BigInt(n) * UNIT;
}

/** Render micro-units as a decimal string with trailing zeros trimmed. */
export function microsToDecimal(m: bigint): string {
  const sign = m < ZERO ? "-" : "";
  const abs = m < ZERO ? -m : m;
  const int = abs / UNIT;
  const frac = (abs % UNIT).toString().padStart(SCALE, "0").replace(/0+$/, "");
  return frac ? `${sign}${int}.${frac}` : `${sign}${int}`;
}

/** Exact division a / b, result in micro-units, truncating to 0. */
export function divMicros(a: bigint, b: bigint): bigint {
  return (a * UNIT) / b;
}

/** Convert micro-units to a float ONLY for pixel rendering (bar widths). */
function microsToFloat(m: bigint): number {
  return Number(m) / Number(UNIT);
}

/**
 * Pure derivation of burnout risk & success probability from a velocity
 * decimal string and an integer upcoming-effort total. Exported for testing
 * so the precision-critical BigInt path is directly verifiable without
 * rendering the component.
 */
export function deriveForecastMetrics(
  velocityDecimal: string,
  upcomingEffort: number,
): { burnoutRisk: boolean; probabilityMicros: bigint } {
  const vel = decimalToMicros(velocityDecimal);
  const load = intToMicros(upcomingEffort);
  const burnoutRisk = load > vel;

  let probability: bigint;
  if (load === ZERO) {
    probability = UNIT;
  } else if (vel <= ZERO) {
    probability = ZERO;
  } else {
    const ratio = divMicros(vel, load);
    probability = ratio >= UNIT ? UNIT : ratio;
  }

  return { burnoutRisk, probabilityMicros: probability };
}

/** ISO date string (YYYY-MM-DD) for local midnight today. */
function localIsoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

/**
 * Sum effort_score for open items due within the next 7 days (window is
 * [today, today+6] inclusive — exactly 7 calendar days). Computed entirely
 * from local state; mirrors VelocityForecastingService::upcomingEffort so
 * the chart updates optimistically before any server round-trip.
 */
export function computeLocalUpcomingEffort(
  items: Item[],
  today: Date = new Date(),
): number {
  const horizon = new Date(today);
  horizon.setDate(horizon.getDate() + 6);

  const todayStr = localIsoDate(today);
  const horizonStr = localIsoDate(horizon);

  let sum = 0;
  for (const item of items) {
    if (!OPEN_STATUSES.has(item.status)) continue;
    if (!item.due_date) continue;
    if (item.due_date < todayStr || item.due_date > horizonStr) continue;
    sum += item.effort_score ?? 1;
  }
  return sum;
}

/**
 * Capacity dashboard: visualises historical weekly effort (bars) against
 * the EMA-smoothed velocity line, plus a live burnout/probability panel.
 *
 * Reactivity: the server is queried once for the velocity baseline (derived
 * from completed items, which don't change during editing). Upcoming effort,
 * burnout risk and success probability are all recomputed from the `items`
 * prop so any optimistic edit — changing effort_score, moving a due_date,
 * dragging a task into this week — repaints the chart instantly.
 */
export function CapacityDashboard({ items, lookbackWeeks = 8 }: Props) {
  const [forecast, setForecast] = useState<VelocityForecast | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchVelocity({ lookback_weeks: lookbackWeeks })
      .then((f) => {
        if (!cancelled) setForecast(f);
      })
      .catch((e) => {
        if (!cancelled) setError(String(e));
      });
    return () => {
      cancelled = true;
    };
  }, [lookbackWeeks]);

  // ─────────────────────────────────────────────────────────────────────────
  // Optimistic derivations — recomputed every time `items` changes.
  // All decision-grade comparisons (burnout, probability) use BigInt
  // micro-units to preserve the precision guaranteed by backend BCMath.
  // ─────────────────────────────────────────────────────────────────────────

  const localUpcoming = useMemo(
    () => computeLocalUpcomingEffort(items),
    [items],
  );

  const velocityMicros = useMemo(
    () => (forecast ? decimalToMicros(forecast.weekly_velocity) : ZERO),
    [forecast],
  );

  const { burnoutRisk, probabilityMicros } = useMemo(
    () =>
      deriveForecastMetrics(
        forecast ? forecast.weekly_velocity : "0",
        localUpcoming,
      ),
    [forecast, localUpcoming],
  );

  // Float projections used ONLY for rendering (bar heights, label text).
  // Decision logic above never touches these.
  const velocityDisplay = useMemo(
    () => microsToDecimal(velocityMicros),
    [velocityMicros],
  );
  const velocityFloat = useMemo(
    () => microsToFloat(velocityMicros),
    [velocityMicros],
  );
  const probabilityFloat = useMemo(
    () => microsToFloat(probabilityMicros),
    [probabilityMicros],
  );

  // Chart scaling: tallest bar is either a historical week or this week's load.
  const chartMax = useMemo(() => {
    const historyMax = forecast
      ? Math.max(0, ...forecast.weekly_history.map((w) => w.effort))
      : 0;
    return Math.max(historyMax, localUpcoming, velocityFloat, 1);
  }, [forecast, localUpcoming, velocityFloat]);

  if (error) {
    return (
      <div className="rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
        Failed to load velocity forecast.
      </div>
    );
  }

  if (!forecast) {
    return (
      <div
        className="h-32 rounded-md bg-slate-100 animate-pulse"
        aria-label="Loading capacity forecast"
      />
    );
  }

  const velocityLinePct = (velocityFloat / chartMax) * 100;

  return (
    <div
      className={`rounded-lg border p-4 transition-colors ${
        burnoutRisk ? "bg-red-50 border-red-300" : "bg-white border-slate-200"
      }`}
      role="region"
      aria-label="Capacity forecast"
    >
      {/* Header */}
      <div className="flex items-start justify-between mb-3">
        <div>
          <h3 className="text-sm font-semibold text-[var(--navy)]">
            Capacity Forecast
          </h3>
          <p className="text-xs text-slate-500">
            Last {forecast.lookback_weeks} weeks · α={forecast.alpha}
          </p>
        </div>
        {burnoutRisk && (
          <span
            className="inline-flex items-center gap-1 rounded-full bg-red-600 px-3 py-1 text-xs font-semibold text-white animate-pulse"
            role="alert"
          >
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
              <path d="M12 2 1 21h22L12 2zm0 5 7.5 13h-15L12 7zm-1 4v4h2v-4h-2zm0 5v2h2v-2h-2z" />
            </svg>
            Burnout Risk
          </span>
        )}
      </div>

      {/* Chart: historical weekly bars + velocity line + this-week bar */}
      <div
        className="relative h-32 flex items-end gap-1 mb-3 px-1"
        role="img"
        aria-label={`Weekly effort history. Velocity ${velocityDisplay} points, upcoming ${localUpcoming} points.`}
      >
        {/* Velocity reference line */}
        <div
          className="absolute left-0 right-0 border-t-2 border-dashed border-emerald-500 z-10 pointer-events-none"
          style={{ bottom: `${velocityLinePct}%` }}
        >
          <span className="absolute right-0 -top-4 text-[10px] font-medium text-emerald-700 bg-white/90 px-1 rounded">
            capacity {velocityDisplay}
          </span>
        </div>

        {/* History bars */}
        {forecast.weekly_history.map((week) => {
          const heightPct = (week.effort / chartMax) * 100;
          return (
            <div
              key={week.week_start}
              className="flex-1 flex flex-col items-center min-w-0"
            >
              <div
                className="w-full rounded-t transition-all duration-300"
                style={{
                  height: `${heightPct}%`,
                  backgroundColor:
                    intToMicros(week.effort) > velocityMicros
                      ? "#fbbf24"
                      : "#cbd5e1",
                }}
                title={`Week of ${week.week_start}: ${week.effort} pts`}
              />
            </div>
          );
        })}

        {/* Separator */}
        <div className="w-px self-stretch bg-slate-300" />

        {/* This-week upcoming bar — the reactive one */}
        <div className="flex-1 flex flex-col items-center min-w-0">
          <div
            className={`w-full rounded-t transition-all duration-200 ${
              burnoutRisk ? "bg-red-500" : "bg-[var(--blue)]"
            }`}
            style={{ height: `${(localUpcoming / chartMax) * 100}%` }}
            title={`This week: ${localUpcoming} pts scheduled`}
          />
        </div>
      </div>

      {/* Metrics row */}
      <div className="grid grid-cols-3 gap-2 text-center">
        <div>
          <div className="text-[10px] uppercase tracking-wide text-slate-500">
            Velocity
          </div>
          <div className="text-lg font-semibold text-[var(--navy)]">
            {velocityDisplay}
            <span className="text-xs font-normal text-slate-400"> pts/wk</span>
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wide text-slate-500">
            This Week
          </div>
          <div
            className={`text-lg font-semibold ${
              burnoutRisk ? "text-red-600" : "text-[var(--navy)]"
            }`}
          >
            {localUpcoming}
            <span className="text-xs font-normal text-slate-400"> pts</span>
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wide text-slate-500">
            Success
          </div>
          <div
            className={`text-lg font-semibold ${
              probabilityMicros < PCT_50
                ? "text-red-600"
                : probabilityMicros < PCT_80
                  ? "text-amber-600"
                  : "text-emerald-600"
            }`}
          >
            {Math.round(probabilityFloat * 100)}%
          </div>
        </div>
      </div>

      {/* Probability bar */}
      <div
        className="mt-2 h-1.5 rounded-full bg-slate-200 overflow-hidden"
        role="progressbar"
        aria-valuenow={Math.round(probabilityFloat * 100)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className={`h-full transition-all duration-300 ${
            probabilityMicros < PCT_50
              ? "bg-red-500"
              : probabilityMicros < PCT_80
                ? "bg-amber-500"
                : "bg-emerald-500"
          }`}
          style={{ width: `${probabilityFloat * 100}%` }}
        />
      </div>
    </div>
  );
}
