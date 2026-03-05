"use client";

import type { VelocityForecast } from "@/types";

interface Props {
  forecast: VelocityForecast | null;
}

/**
 * Surfaces the velocity forecast as a compact inline widget. Pure
 * presentation — all maths lives server-side. Re-renders whenever the
 * parent passes a fresh forecast object.
 */
export function CapacityDashboard({ forecast }: Props) {
  if (!forecast || forecast.sample_weeks === 0) return null;

  const {
    velocity,
    capacity_upper,
    upcoming_load,
    burnout_risk,
    probability_of_success,
    weekly_history,
  } = forecast;

  // Gauge fill: load as a fraction of the one-sigma upper bound, clamped.
  const fillPct = Math.min(
    100,
    capacity_upper > 0 ? (upcoming_load / capacity_upper) * 100 : 100,
  );
  const successPct = Math.round(probability_of_success * 100);
  const historyMax = Math.max(1, ...weekly_history);

  const tone = burnout_risk
    ? {
        ring: "bg-red-50 border-red-200",
        bar: "bg-red-500",
        label: "text-red-700",
      }
    : {
        ring: "bg-emerald-50 border-emerald-200",
        bar: "bg-emerald-500",
        label: "text-emerald-700",
      };

  return (
    <div
      className={`rounded-md border px-3 py-2 ${tone.ring}`}
      role="status"
      aria-live="polite"
    >
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <div className={`text-xs font-semibold ${tone.label}`}>
            {burnout_risk ? "Burnout Risk" : "On Track"}
          </div>
          <div className="text-xs text-slate-600">
            {upcoming_load}
            <span className="text-slate-400"> / </span>
            {capacity_upper.toFixed(1)} pts this week
            <span className="text-slate-400"> · </span>
            {successPct}% likely
          </div>
        </div>

        {/* Sparkline: last N weeks of throughput */}
        <svg
          width={weekly_history.length * 5}
          height="24"
          className="shrink-0"
          aria-label={`Weekly velocity trend, ${weekly_history.length} weeks`}
        >
          {weekly_history.map((v, i) => {
            const h = Math.max(2, (v / historyMax) * 24);
            return (
              <rect
                key={i}
                x={i * 5}
                y={24 - h}
                width="3"
                height={h}
                rx="1"
                className={tone.bar}
                opacity={0.3 + (i / weekly_history.length) * 0.7}
              />
            );
          })}
        </svg>
      </div>

      {/* Capacity gauge */}
      <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
        <div
          className={`h-full transition-all duration-300 ${tone.bar}`}
          style={{ width: `${fillPct}%` }}
        />
      </div>

      <div className="mt-1 text-[10px] text-slate-400">
        velocity {velocity.toFixed(1)} pts/wk
      </div>
    </div>
  );
}
