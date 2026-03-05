"use client";

import type { VelocityForecast } from "@/types";

interface Props {
  forecast: VelocityForecast | null;
  loading: boolean;
}

/**
 * Compact capacity dashboard surfacing the velocity forecast: historical EMA,
 * upcoming required effort, probability of success, and burnout-risk flag.
 * Re-renders instantly when the forecast object changes — reactivity is the
 * caller's responsibility (refresh the hook after item mutations).
 */
export function CapacityDashboard({ forecast, loading }: Props) {
  if (!forecast) {
    return loading ? (
      <div className="rounded-lg border border-slate-200 bg-white p-4 animate-pulse">
        <div className="h-4 w-32 bg-slate-200 rounded mb-3" />
        <div className="grid grid-cols-3 gap-3">
          <div className="h-12 bg-slate-100 rounded" />
          <div className="h-12 bg-slate-100 rounded" />
          <div className="h-12 bg-slate-100 rounded" />
        </div>
      </div>
    ) : null;
  }

  const {
    velocity_ema,
    upcoming_effort,
    capacity_upper_bound,
    probability_of_success,
    burnout_risk,
    weekly_series,
  } = forecast;

  // Fill ratio for the capacity bar. Clamp to [0, 1] so overload still
  // renders a full bar (the colour and badge communicate the overage).
  const loadRatio =
    capacity_upper_bound > 0
      ? Math.min(1, upcoming_effort / capacity_upper_bound)
      : upcoming_effort > 0
        ? 1
        : 0;

  const probabilityPct = Math.round(probability_of_success * 100);

  return (
    <section
      aria-label="Capacity forecast"
      className={`rounded-lg border p-4 transition-colours duration-200 ${
        burnout_risk ? "border-red-300 bg-red-50" : "border-slate-200 bg-white"
      }`}
    >
      {/* Header row */}
      <div className="flex items-start justify-between mb-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-700">
            Capacity — next 7 days
          </h2>
          <p className="text-xs text-slate-500">
            Based on your last {forecast.weeks_analysed} weeks
          </p>
        </div>
        {burnout_risk && (
          <span
            role="alert"
            className="inline-flex items-center gap-1 rounded-full bg-red-600 px-2.5 py-1 text-xs font-semibold text-white"
          >
            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor">
              <path d="M12 2 1 21h22L12 2Zm0 14.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm-1-7h2v6h-2V9.5Z" />
            </svg>
            Burnout risk
          </span>
        )}
      </div>

      {/* Metric tiles */}
      <div className="grid grid-cols-3 gap-3 mb-3">
        <Metric
          label="Velocity"
          value={velocity_ema.toFixed(1)}
          unit="pts/wk"
        />
        <Metric
          label="Upcoming"
          value={String(upcoming_effort)}
          unit="pts due"
          emphasis={burnout_risk}
        />
        <Metric
          label="Odds"
          value={`${probabilityPct}%`}
          unit="of success"
          emphasis={probabilityPct < 50}
        />
      </div>

      {/* Capacity bar: upcoming effort vs upper-bound capacity */}
      <div className="mb-3">
        <div className="flex justify-between text-xs text-slate-500 mb-1">
          <span>Load vs capacity</span>
          <span>
            {upcoming_effort} / {capacity_upper_bound.toFixed(1)}
          </span>
        </div>
        <div className="h-2 rounded-full bg-slate-200 overflow-hidden">
          <div
            className={`h-full transition-all duration-300 ${
              burnout_risk ? "bg-red-500" : "bg-emerald-500"
            }`}
            style={{ width: `${loadRatio * 100}%` }}
          />
        </div>
      </div>

      {/* Weekly sparkline */}
      <Sparkline series={weekly_series} />
    </section>
  );
}

function Metric({
  label,
  value,
  unit,
  emphasis = false,
}: {
  label: string;
  value: string;
  unit: string;
  emphasis?: boolean;
}) {
  return (
    <div className="text-center">
      <div className="text-[10px] uppercase tracking-wider text-slate-500">
        {label}
      </div>
      <div
        className={`text-lg font-semibold tabular-nums ${
          emphasis ? "text-red-600" : "text-slate-800"
        }`}
      >
        {value}
      </div>
      <div className="text-[10px] text-slate-400">{unit}</div>
    </div>
  );
}

/**
 * Minimal bar sparkline of weekly completed effort, oldest → newest.
 * Heights are normalised to the series maximum.
 */
function Sparkline({ series }: { series: number[] }) {
  if (series.length === 0) return null;
  const max = Math.max(...series, 1);

  return (
    <div>
      <div className="text-[10px] uppercase tracking-wider text-slate-500 mb-1">
        Weekly throughput
      </div>
      <div className="flex items-end gap-0.5 h-8">
        {series.map((v, i) => (
          <div
            key={i}
            className="flex-1 bg-slate-300 rounded-sm transition-all duration-200"
            style={{ height: `${Math.max(8, (v / max) * 100)}%` }}
            title={`Week ${i + 1}: ${v} pts`}
          />
        ))}
      </div>
    </div>
  );
}
