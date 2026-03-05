"use client";

import { useMemo } from "react";
import type { VelocityForecast } from "@/types";

interface Props {
  /**
   * The forecast to visualise. Caller is responsible for sourcing this —
   * either from the server (GET /api/velocity) or derived locally via
   * lib/velocity.deriveForecast() for instant optimistic updates.
   */
  forecast: VelocityForecast;
  /**
   * When true, renders a skeleton instead of the chart. Only use for the
   * very first load — subsequent refetches should display stale-then-
   * revalidate so the chart never blanks out while the user is watching it.
   */
  loading?: boolean;
}

const CHART_HEIGHT = 96;

/**
 * Compact capacity dashboard showing:
 *  – weekly-effort history bars (grey) vs. the EMA capacity line (blue)
 *  – a prominent upcoming-effort bar (amber/red depending on burnout state)
 *  – success-probability percentage
 *  – a burnout-risk banner when upcoming > capacity
 *
 * Pure function of its props: re-rendering with a new `forecast` instantly
 * reflects effort-score changes made elsewhere in the app.
 */
export function CapacityDashboard({ forecast, loading = false }: Props) {
  const capacity = Number.parseFloat(forecast.weekly_velocity_ema);
  const upcoming = forecast.upcoming_effort;
  const probability = Number.parseFloat(forecast.success_probability);
  const burnout = forecast.burnout_risk;

  // Build the chart series: historical weeks + the upcoming-week column.
  // The EMA line is drawn at the same vertical scale so height comparisons
  // are meaningful at a glance.
  const { bars, maxValue, emaY } = useMemo(() => {
    const hist = forecast.weekly_history.slice(-8); // keep the widget compact
    const points = [
      ...hist.map((w) => ({
        label: w.week_start.slice(5),
        value: w.effort,
        kind: "history" as const,
      })),
      {
        label: "Next 7d",
        value: upcoming,
        kind: "upcoming" as const,
      },
    ];
    const max = Math.max(capacity, upcoming, ...hist.map((w) => w.effort), 1);
    const emaRatio = capacity / max;
    return { bars: points, maxValue: max, emaY: CHART_HEIGHT * (1 - emaRatio) };
  }, [forecast.weekly_history, upcoming, capacity]);

  if (loading) {
    return (
      <section
        aria-label="Capacity forecast"
        className="rounded-xl bg-white border border-slate-200 p-4 animate-pulse"
      >
        <div className="h-4 w-32 bg-slate-200 rounded mb-3" />
        <div className="h-24 bg-slate-100 rounded" />
      </section>
    );
  }

  return (
    <section
      aria-label="Capacity forecast"
      data-testid="capacity-dashboard"
      className="rounded-xl bg-white border border-slate-200 p-4 shadow-sm"
    >
      {/* Burnout banner */}
      {burnout && (
        <div
          role="alert"
          data-testid="burnout-banner"
          className="mb-3 flex items-center gap-2 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700"
        >
          <svg
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            aria-hidden="true"
          >
            <path
              d="M12 9v4m0 4h.01M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          <span>
            <strong>Burnout Risk.</strong> {upcoming} pts scheduled vs.{" "}
            {capacity.toFixed(1)} pts weekly capacity.
          </span>
        </div>
      )}

      {/* Header stats */}
      <div className="flex items-baseline justify-between mb-3">
        <h3 className="text-sm font-semibold text-slate-700">Capacity</h3>
        <div className="flex items-baseline gap-3 text-xs">
          <Stat
            label="Velocity"
            value={`${capacity.toFixed(1)} pts/wk`}
            testId="stat-velocity"
          />
          <Stat
            label="Upcoming"
            value={`${upcoming} pts`}
            testId="stat-upcoming"
            accent={burnout ? "danger" : undefined}
          />
          <Stat
            label="Success"
            value={`${Math.round(probability * 100)}%`}
            testId="stat-probability"
            accent={probability >= 0.8 ? "ok" : burnout ? "danger" : "warn"}
          />
        </div>
      </div>

      {/* Bar chart */}
      <div
        className="relative flex items-end gap-1"
        style={{ height: CHART_HEIGHT }}
        role="img"
        aria-label={`Weekly effort history and upcoming load. Capacity ${capacity.toFixed(1)} points per week.`}
      >
        {/* EMA capacity line */}
        {capacity > 0 && (
          <div
            className="absolute inset-x-0 border-t-2 border-dashed border-sky-500/70 pointer-events-none"
            style={{ top: emaY }}
          >
            <span className="absolute right-0 -top-4 text-[10px] text-sky-600 font-medium bg-white px-1 rounded">
              capacity
            </span>
          </div>
        )}

        {bars.map((bar, i) => {
          const heightPct = (bar.value / maxValue) * 100;
          const isUpcoming = bar.kind === "upcoming";
          const barColour = isUpcoming
            ? burnout
              ? "bg-red-500"
              : "bg-amber-500"
            : "bg-slate-300";
          return (
            <div
              key={`${bar.label}-${i}`}
              className="flex-1 flex flex-col items-center justify-end gap-1 min-w-0"
            >
              <div
                className={`w-full rounded-t transition-all duration-300 ${barColour}`}
                style={{ height: `${heightPct}%` }}
                title={`${bar.label}: ${bar.value} pts`}
              />
              <span className="text-[9px] text-slate-400 truncate max-w-full">
                {bar.label}
              </span>
            </div>
          );
        })}
      </div>

      {forecast.history_weeks === 0 && (
        <p className="mt-2 text-[11px] text-slate-400">
          Complete some tasks to build your velocity baseline.
        </p>
      )}
    </section>
  );
}

function Stat({
  label,
  value,
  testId,
  accent,
}: {
  label: string;
  value: string;
  testId: string;
  accent?: "ok" | "warn" | "danger";
}) {
  const colour =
    accent === "ok"
      ? "text-emerald-600"
      : accent === "warn"
        ? "text-amber-600"
        : accent === "danger"
          ? "text-red-600"
          : "text-slate-700";
  return (
    <span className="flex flex-col items-end leading-tight">
      <span className="text-slate-400">{label}</span>
      <strong data-testid={testId} className={`${colour} tabular-nums`}>
        {value}
      </strong>
    </span>
  );
}
