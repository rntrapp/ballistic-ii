import type { Item, VelocityForecast } from "@/types";

/** Forecast horizon in days — mirrors backend FORECAST_HORIZON_DAYS. */
const HORIZON_DAYS = 7;

/** 4-dp drift guard — mirrors backend DRIFT_PRECISION. */
const DRIFT_ROUND = 1e4;

/**
 * Client-side mirror of `VelocityForecastingService::sumUpcomingEffort`.
 * Sums effort for open items due within the next {HORIZON_DAYS} calendar
 * dates — today counted as day 1, so the last day is today + (HORIZON − 1).
 *
 * Date comparison is done on ISO date strings (YYYY-MM-DD), which sort
 * lexicographically, so no Date parsing round-trip is needed.
 */
export function sumUpcomingEffort(items: readonly Item[]): number {
  const today = new Date();
  const todayStr = today.toISOString().slice(0, 10);

  const lastDay = new Date(today);
  lastDay.setDate(lastDay.getDate() + (HORIZON_DAYS - 1));
  const lastDayStr = lastDay.toISOString().slice(0, 10);

  let sum = 0;
  for (const item of items) {
    if (item.status === "done" || item.status === "wontdo") continue;
    if (!item.due_date) continue;
    if (item.due_date < todayStr || item.due_date > lastDayStr) continue;
    sum += item.effort_score;
  }
  return sum;
}

/**
 * Standard normal CDF Φ(x), Abramowitz & Stegun 26.2.17.
 * |error| < 7.5e-8 — same approximation the backend uses.
 */
export function normalCdf(x: number): number {
  const absX = Math.abs(x);
  const t = 1 / (1 + 0.2316419 * absX);

  const poly =
    t *
    (0.31938153 +
      t *
        (-0.356563782 +
          t * (1.781477937 + t * (-1.821255978 + t * 1.330274429))));

  const phi = 0.3989422804014327 * Math.exp(-0.5 * absX * absX);
  const upperTail = phi * poly;

  return x >= 0 ? 1 - upperTail : upperTail;
}

/**
 * Re-derive the mutable half of a forecast — the fields that depend on
 * `upcoming_effort` — from a new upcoming value and the server's immutable
 * EMA/σ. Lets the dashboard react on the same render as an optimistic
 * `setItems`, with no server round-trip.
 */
export function composeLiveForecast(
  server: VelocityForecast,
  liveUpcoming: number,
): VelocityForecast {
  const ema = server.velocity_ema;
  const sigma = server.velocity_std_dev;
  const capacity = ema + sigma;

  // Same 4-dp drift guard as backend before the inequality.
  const capacityRounded = Math.round(capacity * DRIFT_ROUND) / DRIFT_ROUND;
  const burnout = liveUpcoming > capacityRounded;

  let probability: number;
  if (liveUpcoming === 0) {
    probability = 1;
  } else if (sigma <= 0) {
    const meanRounded = Math.round(ema * DRIFT_ROUND) / DRIFT_ROUND;
    probability = liveUpcoming <= meanRounded ? 1 : 0;
  } else {
    probability = normalCdf((ema - liveUpcoming) / sigma);
  }

  return {
    ...server,
    upcoming_effort: liveUpcoming,
    capacity_upper_bound: capacity,
    probability_of_success: probability,
    burnout_risk: burnout,
  };
}
