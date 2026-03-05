/**
 * Client-side mirror of App\Services\VelocityForecastingService.
 *
 * The backend computes the canonical forecast with BCMath at 12 decimal
 * places and rounds to 4 dp for the wire. This module reproduces those
 * semantics exactly using native BigInt fixed-point arithmetic — no
 * IEEE-754 floats, no epsilon fudge factors, no drift.
 *
 * Fixed-point representation: value × 10^BCSCALE stored in a BigInt.
 *   - add/sub are native BigInt ops (exact)
 *   - mul = (a * b) / ONE, truncating toward zero (matches bcmul)
 *   - div = (a * ONE) / b, truncating toward zero (matches bcdiv)
 *   - cmp is native BigInt compare (matches bccomp)
 *   - output rounding is add-half-then-truncate (matches the service's
 *     round() helper)
 *
 * Public functions accept and return decimal strings so callers can pass
 * the server's 4-dp EMA straight through without ever touching Number —
 * the string stays precise end-to-end.
 *
 * BigInt is an ECMAScript built-in: no dependency footprint.
 */

import type { Item, VelocityForecast } from "@/types";

export const DEFAULT_ALPHA = 0.2;
const HORIZON_DAYS = 7;
const MAX_LOOKBACK_WEEKS = 26;

// ─────────────────────────────────────────────────────────────────────────────
// Fixed-point BigInt arithmetic (mirrors BCMath at BCSCALE dp)
// ─────────────────────────────────────────────────────────────────────────────

/** A BigInt representing value × 10^BCSCALE. 1.0 === ONE, 0.5 === ONE / 2n. */
type Fixed = bigint;

const BCSCALE = 12;
const OUTPUT_SCALE = 4;
// BigInt() constructor form because tsconfig targets ES2017 (no `n` literals);
// lib: esnext provides the type, and SWC emits native BigInt at runtime.
const TEN = BigInt(10);
const ONE: Fixed = TEN ** BigInt(BCSCALE);
const TWO: Fixed = BigInt(2) * ONE;
const ZERO: Fixed = BigInt(0);

/** Lift a safe JS integer to fixed-point. */
function fxFromInt(n: number): Fixed {
  return BigInt(n) * ONE;
}

/**
 * Lift a JS number to fixed-point via a canonicalised decimal string.
 * Mirrors the backend's sprintf('%.12F', (float) $x): Number.toFixed is
 * locale-insensitive and rounds half-to-even-ish per IEEE-754, which for
 * inputs in [0, 1] at 12 dp is lossless (doubles carry ~15 sigfigs).
 *
 * Used only for the alpha smoothing factor, which lives in (0, 1].
 */
function fxFromNumber(n: number): Fixed {
  return fxFromString(n.toFixed(BCSCALE));
}

/**
 * Parse a fixed-point decimal string (e.g. "10.9360", "-0.5", "3") to
 * Fixed. Rejects NaN silently by treating missing digits as zero — callers
 * only feed validated server-provided or internally-generated strings here.
 */
function fxFromString(s: string): Fixed {
  const neg = s.startsWith("-");
  const body = neg ? s.slice(1) : s;
  const dot = body.indexOf(".");

  const intPart = dot >= 0 ? body.slice(0, dot) : body;
  const decPart = dot >= 0 ? body.slice(dot + 1) : "";

  // Right-pad to BCSCALE; truncate if the string has more precision than
  // we store (matches bcadd($x, '0', BCSCALE) behaviour).
  const decScaled = decPart.padEnd(BCSCALE, "0").slice(0, BCSCALE);

  const magnitude = BigInt(intPart || "0") * ONE + BigInt(decScaled || "0");
  return neg ? -magnitude : magnitude;
}

/** (a × b) ÷ 10^BCSCALE, truncating toward zero — matches bcmul. */
function fxMul(a: Fixed, b: Fixed): Fixed {
  return (a * b) / ONE;
}

/** (a × 10^BCSCALE) ÷ b, truncating toward zero — matches bcdiv. */
function fxDiv(a: Fixed, b: Fixed): Fixed {
  return (a * ONE) / b;
}

/** Three-way compare — matches bccomp. */
function fxCmp(a: Fixed, b: Fixed): -1 | 0 | 1 {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * Round half-up and format as a decimal string at `scale` decimal places.
 * Add-half-then-truncate mirrors VelocityForecastingService::round().
 */
function fxToString(f: Fixed, scale: number = OUTPUT_SCALE): string {
  const unit = TEN ** BigInt(BCSCALE - scale);
  const half = unit / BigInt(2);
  const adjusted = f >= ZERO ? f + half : f - half;
  const rescaled = adjusted / unit; // now at `scale` dp

  const neg = rescaled < ZERO;
  const abs = neg ? -rescaled : rescaled;
  const base = TEN ** BigInt(scale);
  const intPart = abs / base;
  const decPart = (abs % base).toString().padStart(scale, "0");
  return `${neg ? "-" : ""}${intPart}.${decPart}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Forecast core
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Derive a complete forecast purely from the in-memory item list. Shape
 * matches GET /api/velocity so callers can swap the server payload in
 * transparently once it arrives.
 */
export function deriveForecast(
  items: readonly Item[],
  now: Date = new Date(),
  alpha: number = DEFAULT_ALPHA,
): VelocityForecast {
  const alphaFx = normaliseAlpha(alpha);

  const weeklyHistory = aggregateWeeklyEffort(items, now);
  const emaFx = emaFixed(
    weeklyHistory.map((w) => w.effort),
    alphaFx,
  );
  const upcoming = sumUpcomingEffort(items, now);
  const probFx = probabilityFixed(emaFx, upcoming);

  return {
    weekly_velocity_ema: fxToString(emaFx),
    upcoming_effort: upcoming,
    success_probability: fxToString(probFx),
    burnout_risk: burnoutFixed(emaFx, upcoming),
    alpha: fxToString(alphaFx),
    history_weeks: weeklyHistory.length,
    weekly_history: weeklyHistory,
  };
}

/**
 * EMA_t = alpha * X_t + (1 - alpha) * EMA_{t-1}
 * Seeded with EMA_0 = X_0 (no zero bias).
 *
 * All arithmetic in BigInt fixed-point; result is bit-identical to the
 * backend's BCMath output for the same series + alpha.
 *
 * series[0] is the oldest observation. Returns a 4-dp decimal string.
 */
export function exponentialMovingAverage(
  series: readonly number[],
  alpha: number,
): string {
  return fxToString(emaFixed(series, normaliseAlpha(alpha)));
}

/**
 * Linear-decay probability model — same constants as the backend:
 *   load = upcoming / capacity
 *   load <= 1.0 -> p = 1.0
 *   load  > 1.0 -> p = max(0, 2 - load)
 * When capacity is zero but load exists, p = 0.
 * When both are zero, p = 1.0 (nothing to do).
 *
 * `capacity` is a decimal string (e.g. the server's weekly_velocity_ema);
 * it is parsed straight to fixed-point — never to a float. Returns a
 * 4-dp decimal string.
 */
export function calculateSuccessProbability(
  capacity: string,
  upcomingEffort: number,
): string {
  return fxToString(probabilityFixed(fxFromString(capacity), upcomingEffort));
}

/**
 * Burnout when upcoming strictly exceeds capacity. Exact BigInt comparison
 * — no epsilon, no drift, identical to the backend's bccomp.
 *
 * `capacity` is a decimal string, parsed straight to fixed-point.
 */
export function isBurnoutRisk(
  capacity: string,
  upcomingEffort: number,
): boolean {
  return burnoutFixed(fxFromString(capacity), upcomingEffort);
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixed-point core (internal — work on Fixed, no string round-trips)
// ─────────────────────────────────────────────────────────────────────────────

function emaFixed(series: readonly number[], alphaFx: Fixed): Fixed {
  if (series.length === 0) return ZERO;

  const oneMinusAlpha = ONE - alphaFx;

  // EMA_0 = X_0
  let ema = fxFromInt(series[0]);
  for (let i = 1; i < series.length; i++) {
    const term1 = fxMul(alphaFx, fxFromInt(series[i]));
    const term2 = fxMul(oneMinusAlpha, ema);
    ema = term1 + term2;
  }
  return ema;
}

function probabilityFixed(capacityFx: Fixed, upcoming: number): Fixed {
  if (upcoming <= 0) return ONE;
  if (fxCmp(capacityFx, ZERO) <= 0) return ZERO;

  const load = fxDiv(fxFromInt(upcoming), capacityFx);
  if (fxCmp(load, ONE) <= 0) return ONE;

  const p = TWO - load;
  return fxCmp(p, ZERO) < 0 ? ZERO : p;
}

function burnoutFixed(capacityFx: Fixed, upcoming: number): boolean {
  return fxCmp(fxFromInt(upcoming), capacityFx) > 0;
}

/**
 * Clamp alpha to (0, 1] and lift to fixed-point. Mirrors the backend's
 * normaliseAlpha — non-finite or ≤0 falls back to DEFAULT_ALPHA, >1
 * clamps to 1.
 */
function normaliseAlpha(alpha: number): Fixed {
  if (!Number.isFinite(alpha) || alpha <= 0) {
    return fxFromNumber(DEFAULT_ALPHA);
  }
  if (alpha > 1) return ONE;
  return fxFromNumber(alpha);
}

// ─────────────────────────────────────────────────────────────────────────────
// Aggregations (pure integer — already exact, no fixed-point needed)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Bucket completed items into ISO weeks (Monday start). Returns oldest-first,
 * zero-filling gap weeks so EMA decays correctly during idle periods. Only
 * the most recent MAX_LOOKBACK_WEEKS complete weeks are considered.
 */
export function aggregateWeeklyEffort(
  items: readonly Item[],
  now: Date,
): { week_start: string; effort: number }[] {
  const currentWeekStart = startOfIsoWeek(now);
  const lookbackStart = addDays(currentWeekStart, -7 * MAX_LOOKBACK_WEEKS);

  const buckets = new Map<string, number>();

  for (const item of items) {
    if (!item.completed_at) continue;
    const completed = new Date(item.completed_at);
    if (completed < lookbackStart || completed >= currentWeekStart) continue;

    const key = toDateString(startOfIsoWeek(completed));
    buckets.set(key, (buckets.get(key) ?? 0) + item.effort_score);
  }

  if (buckets.size === 0) return [];

  const observedStarts = [...buckets.keys()].sort();
  const firstObserved = new Date(observedStarts[0] + "T00:00:00");

  const history: { week_start: string; effort: number }[] = [];
  let cursor = firstObserved;
  while (cursor < currentWeekStart) {
    const key = toDateString(cursor);
    history.push({ week_start: key, effort: buckets.get(key) ?? 0 });
    cursor = addDays(cursor, 7);
  }
  return history;
}

/**
 * Sum of effort for open items due within the half-open interval
 * [today, today + HORIZON_DAYS). With HORIZON_DAYS = 7 this is exactly
 * 7 calendar days: today through today+6 inclusive, excluding today+7.
 */
export function sumUpcomingEffort(items: readonly Item[], now: Date): number {
  const todayStr = toDateString(now);
  const horizonStr = toDateString(addDays(now, HORIZON_DAYS));

  let total = 0;
  for (const item of items) {
    if (!item.due_date) continue;
    if (item.status === "done" || item.status === "wontdo") continue;
    if (item.due_date < todayStr || item.due_date >= horizonStr) continue;
    total += item.effort_score;
  }
  return total;
}

// ─────────────────────────────────────────────────────────────────────────────
// Date helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Returns 00:00 local-time Monday of the ISO week containing `d`. */
function startOfIsoWeek(d: Date): Date {
  const copy = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  // JS: Sunday=0, Monday=1. Map Sunday to 7 so Monday becomes offset 0.
  const day = copy.getDay() || 7;
  copy.setDate(copy.getDate() - (day - 1));
  return copy;
}

function addDays(d: Date, days: number): Date {
  const copy = new Date(d);
  copy.setDate(copy.getDate() + days);
  return copy;
}

function toDateString(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}
