import { loadContribution, reforecast } from "@/lib/forecast";
import type { Item, VelocityForecast } from "@/types";

const now = new Date("2026-03-05T12:00:00Z");
const iso = (daysFromNow: number) =>
  new Date(now.getTime() + daysFromNow * 86_400_000).toISOString().slice(0, 10);

function item(overrides: Partial<Item>): Item {
  return {
    id: "x",
    user_id: "u",
    assignee_id: null,
    project_id: null,
    title: "t",
    description: null,
    status: "todo",
    position: 0,
    effort_score: 1,
    scheduled_date: null,
    due_date: null,
    completed_at: null,
    recurrence_rule: null,
    recurrence_parent_id: null,
    recurrence_strategy: null,
    is_recurring_template: false,
    is_recurring_instance: false,
    assignee_notes: null,
    is_assigned: false,
    is_delegated: false,
    created_at: "2026-01-01T00:00:00Z",
    updated_at: "2026-01-01T00:00:00Z",
    deleted_at: null,
    ...overrides,
  };
}

describe("loadContribution", () => {
  test("returns effort for open item due within 7 days", () => {
    const it = item({ status: "todo", due_date: iso(3), effort_score: 5 });
    expect(loadContribution(it, now)).toBe(5);
  });

  test("doing status also contributes", () => {
    const it = item({ status: "doing", due_date: iso(1), effort_score: 8 });
    expect(loadContribution(it, now)).toBe(8);
  });

  test("done items contribute zero", () => {
    const it = item({ status: "done", due_date: iso(3), effort_score: 5 });
    expect(loadContribution(it, now)).toBe(0);
  });

  test("wontdo items contribute zero", () => {
    const it = item({ status: "wontdo", due_date: iso(3), effort_score: 5 });
    expect(loadContribution(it, now)).toBe(0);
  });

  test("missing due_date contributes zero", () => {
    const it = item({ status: "todo", due_date: null, effort_score: 5 });
    expect(loadContribution(it, now)).toBe(0);
  });

  test("due today contributes", () => {
    const it = item({ status: "todo", due_date: iso(0), effort_score: 3 });
    expect(loadContribution(it, now)).toBe(3);
  });

  test("due at horizon contributes", () => {
    const it = item({ status: "todo", due_date: iso(7), effort_score: 2 });
    expect(loadContribution(it, now)).toBe(2);
  });

  test("due past horizon contributes zero", () => {
    const it = item({ status: "todo", due_date: iso(8), effort_score: 5 });
    expect(loadContribution(it, now)).toBe(0);
  });

  test("overdue contributes zero", () => {
    const it = item({ status: "todo", due_date: iso(-1), effort_score: 5 });
    expect(loadContribution(it, now)).toBe(0);
  });

  // This is the reactivity driver: effort 1→8 on a due-this-week task
  // produces delta = 8 - 1 = 7.
  test("bumping effort 1 to 8 yields delta of 7", () => {
    const before = item({ status: "todo", due_date: iso(3), effort_score: 1 });
    const after = { ...before, effort_score: 8 };
    expect(loadContribution(after, now) - loadContribution(before, now)).toBe(
      7,
    );
  });

  test("clearing due_date on a contributing item yields negative delta", () => {
    const before = item({ status: "todo", due_date: iso(3), effort_score: 5 });
    const after = { ...before, due_date: null };
    expect(loadContribution(after, now) - loadContribution(before, now)).toBe(
      -5,
    );
  });
});

describe("reforecast", () => {
  const base: VelocityForecast = {
    velocity: 10,
    std_dev: 2,
    capacity_upper: 12,
    upcoming_load: 5,
    burnout_risk: false,
    probability_of_success: 0.9938,
    weekly_history: [8, 10, 12, 10],
    sample_weeks: 4,
  };

  test("preserves historical basis", () => {
    const out = reforecast(base, 20);
    expect(out.velocity).toBe(10);
    expect(out.std_dev).toBe(2);
    expect(out.weekly_history).toEqual([8, 10, 12, 10]);
    expect(out.sample_weeks).toBe(4);
  });

  // Spec case: velocity=10, load=25 → burnout
  test("load grossly exceeding capacity flips burnout", () => {
    const out = reforecast(base, 25);
    expect(out.burnout_risk).toBe(true);
    expect(out.upcoming_load).toBe(25);
  });

  test("load at exactly velocity with zero std_dev is not burnout", () => {
    const zeroVar = { ...base, std_dev: 0, capacity_upper: 10 };
    const out = reforecast(zeroVar, 10);
    expect(out.burnout_risk).toBe(false);
    expect(out.probability_of_success).toBe(1);
  });

  test("load one above velocity with zero std_dev is burnout", () => {
    const zeroVar = { ...base, std_dev: 0 };
    const out = reforecast(zeroVar, 11);
    expect(out.burnout_risk).toBe(true);
    expect(out.probability_of_success).toBe(0);
  });

  test("probability is 0.5 when load equals mean", () => {
    const out = reforecast(base, 10);
    expect(out.probability_of_success).toBeCloseTo(0.5, 3);
  });

  test("probability decreases monotonically as load increases", () => {
    const probs = [5, 8, 10, 12, 15, 20].map(
      (l) => reforecast(base, l).probability_of_success,
    );
    for (let i = 1; i < probs.length; i++) {
      expect(probs[i]).toBeLessThan(probs[i - 1]);
    }
  });

  test("probability stays within [0, 1]", () => {
    for (const load of [0, 1, 10, 50, 100, 1000]) {
      const p = reforecast(base, load).probability_of_success;
      expect(p).toBeGreaterThanOrEqual(0);
      expect(p).toBeLessThanOrEqual(1);
    }
  });

  // Cross-check against the PHP service: Φ(1) ≈ 0.8413, so P(X ≥ μ+σ) ≈ 0.1587
  test("normal CDF matches known value at one sigma", () => {
    const out = reforecast(base, 12); // z = (12 - 10) / 2 = 1
    expect(out.probability_of_success).toBeCloseTo(0.1587, 3);
  });

  test("negative load clamps to zero", () => {
    const out = reforecast(base, -5);
    expect(out.upcoming_load).toBe(0);
    expect(out.burnout_risk).toBe(false);
  });

  // Reactivity round-trip: apply delta, derived state flips, reverse delta, flips back.
  test("applying and reversing a delta is idempotent on burnout", () => {
    const safe = reforecast(base, 5);
    const danger = reforecast(safe, safe.upcoming_load + 20);
    const back = reforecast(danger, danger.upcoming_load - 20);
    expect(safe.burnout_risk).toBe(false);
    expect(danger.burnout_risk).toBe(true);
    expect(back.burnout_risk).toBe(false);
    expect(back.upcoming_load).toBe(safe.upcoming_load);
  });
});
