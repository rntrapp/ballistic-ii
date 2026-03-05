import type { Item, VelocityForecast } from "@/types";

const HORIZON_DAYS = 7;

/**
 * How much effort an item contributes to the upcoming-week load.
 * Mirrors VelocityForecastingService::upcomingLoad on the server:
 * open status, due_date present and within [today, today + 7 days].
 */
export function loadContribution(item: Item, now: Date = new Date()): number {
  if (item.status !== "todo" && item.status !== "doing") return 0;
  if (!item.due_date) return 0;

  const today = now.toISOString().slice(0, 10);
  const horizon = new Date(now.getTime() + HORIZON_DAYS * 86_400_000)
    .toISOString()
    .slice(0, 10);

  if (item.due_date < today || item.due_date > horizon) return 0;
  return item.effort_score;
}

/**
 * Re-derive the reactive fields (burnout_risk, probability_of_success,
 * upcoming_load) from a server-supplied historical basis + a new load.
 * Velocity / std_dev / history are stable — they depend only on completed
 * tasks, which don't change when you bump an open task's effort.
 */
export function reforecast(
  base: VelocityForecast,
  newLoad: number,
): VelocityForecast {
  const load = Math.max(0, newLoad);
  const capacityUpper = base.velocity + base.std_dev;

  return {
    ...base,
    upcoming_load: load,
    capacity_upper: capacityUpper,
    burnout_risk: load > capacityUpper,
    probability_of_success: probabilityOfSuccess(
      load,
      base.velocity,
      base.std_dev,
    ),
  };
}

/** P(weekly throughput ≥ load) under N(velocity, std_dev²). */
function probabilityOfSuccess(
  load: number,
  mean: number,
  stdDev: number,
): number {
  if (stdDev <= 0) return load <= mean ? 1 : 0;
  const z = (load - mean) / stdDev;
  return round4(1 - normalCdf(z));
}

/**
 * Abramowitz & Stegun 26.2.17 (Zelen & Severo), |ε| < 7.5e-8.
 * Same polynomial as the PHP service so client/server agree.
 */
function normalCdf(z: number): number {
  const p = 0.2316419;
  const b = [0.31938153, -0.356563782, 1.781477937, -1.821255978, 1.330274429];

  const a = Math.abs(z);
  const t = 1 / (1 + p * a);
  const phi = Math.exp(-0.5 * a * a) / Math.sqrt(2 * Math.PI);
  const poly = t * (b[0] + t * (b[1] + t * (b[2] + t * (b[3] + t * b[4]))));
  const upper = phi * poly;

  return z >= 0 ? 1 - upper : upper;
}

function round4(x: number): number {
  return Math.round(x * 10_000) / 10_000;
}
