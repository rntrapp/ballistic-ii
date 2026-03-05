/**
 * Verifies the delta-overlay reactivity contract: changing an item's effort
 * score re-derives the forecast synchronously, without a server round-trip.
 */
import { renderHook, act } from "@testing-library/react";
import { useVelocityForecast } from "@/hooks/useVelocityForecast";
import {
  composeLiveForecast,
  normalCdf,
  sumUpcomingEffort,
} from "@/lib/forecast";
import type { Item, VelocityForecast } from "@/types";

jest.mock("@/lib/api", () => ({
  fetchVelocityForecast: jest.fn(),
}));

import { fetchVelocityForecast } from "@/lib/api";
const mockFetch = fetchVelocityForecast as jest.Mock;

// Minimal fixture — only fields the overlay reads matter.
function makeItem(overrides: Partial<Item>): Item {
  return {
    id: "i",
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
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
    deleted_at: null,
    ...overrides,
  };
}

const tomorrow = (() => {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
})();

describe("sumUpcomingEffort", () => {
  it("counts open items due within 7 days", () => {
    const items = [
      makeItem({ id: "a", due_date: tomorrow, effort_score: 3 }),
      makeItem({ id: "b", due_date: tomorrow, effort_score: 5 }),
      makeItem({
        id: "c",
        due_date: tomorrow,
        effort_score: 8,
        status: "done",
      }),
      makeItem({ id: "d", due_date: null, effort_score: 8 }),
    ];
    expect(sumUpcomingEffort(items)).toBe(8);
  });

  it("excludes items due beyond the 7-day horizon", () => {
    const far = new Date();
    far.setDate(far.getDate() + 30);
    const items = [
      makeItem({ due_date: far.toISOString().slice(0, 10), effort_score: 8 }),
    ];
    expect(sumUpcomingEffort(items)).toBe(0);
  });

  it("window is exactly 7 dates — day 7 in, day 8 out (fencepost)", () => {
    // Today is day 1, so today+6 is day 7 (last in), today+7 is day 8 (out).
    const plus = (n: number) => {
      const d = new Date();
      d.setDate(d.getDate() + n);
      return d.toISOString().slice(0, 10);
    };

    const items = [
      makeItem({ id: "d1", due_date: plus(0), effort_score: 1 }), // today — in
      makeItem({ id: "d7", due_date: plus(6), effort_score: 2 }), // day 7 — in
      makeItem({ id: "d8", due_date: plus(7), effort_score: 5 }), // day 8 — OUT
    ];

    // 1 + 2 = 3. Would be 8 under the old 8-day bug.
    expect(sumUpcomingEffort(items)).toBe(3);
  });
});

describe("normalCdf", () => {
  it("is 0.5 at z = 0", () => {
    expect(normalCdf(0)).toBeCloseTo(0.5, 6);
  });

  it("approaches 1 for large positive z", () => {
    expect(normalCdf(3)).toBeCloseTo(0.99865, 4);
  });

  it("is symmetric about 0.5", () => {
    expect(normalCdf(-1.5) + normalCdf(1.5)).toBeCloseTo(1, 6);
  });
});

describe("composeLiveForecast", () => {
  const server: VelocityForecast = {
    velocity_ema: 10,
    velocity_std_dev: 2,
    upcoming_effort: 5,
    capacity_upper_bound: 12,
    probability_of_success: 0.99,
    burnout_risk: false,
    weeks_analysed: 12,
    weekly_series: [8, 9, 10, 11, 10, 10, 12, 9, 10, 11, 10, 10],
  };

  it("raising upcoming above EMA + sigma flips burnout", () => {
    expect(composeLiveForecast(server, 11).burnout_risk).toBe(false);
    expect(composeLiveForecast(server, 12).burnout_risk).toBe(false); // boundary
    expect(composeLiveForecast(server, 13).burnout_risk).toBe(true);
  });

  it("zero upcoming gives certainty", () => {
    expect(composeLiveForecast(server, 0).probability_of_success).toBe(1);
  });

  it("zero sigma collapses to a hard threshold", () => {
    const flat = { ...server, velocity_std_dev: 0 };
    expect(composeLiveForecast(flat, 10).probability_of_success).toBe(1);
    expect(composeLiveForecast(flat, 11).probability_of_success).toBe(0);
  });
});

describe("useVelocityForecast — reactive delta overlay", () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it("updates forecast on the same render when effort changes, no refetch", async () => {
    // Server says 10 pts/week velocity, 3 pts currently upcoming.
    mockFetch.mockResolvedValue({
      velocity_ema: 10,
      velocity_std_dev: 2,
      upcoming_effort: 3,
      capacity_upper_bound: 12,
      probability_of_success: 0.9997,
      burnout_risk: false,
      weeks_analysed: 12,
      weekly_series: Array(12).fill(10),
    } satisfies VelocityForecast);

    const initialItems = [
      makeItem({ id: "x", due_date: tomorrow, effort_score: 3 }),
    ];

    const { result, rerender } = renderHook(
      ({ items }) => useVelocityForecast(true, items),
      { initialProps: { items: initialItems } },
    );

    // Let the initial fetch resolve & baseline snapshot.
    await act(async () => {});

    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(result.current.forecast?.upcoming_effort).toBe(3);
    expect(result.current.forecast?.burnout_risk).toBe(false);

    // Now the user bumps effort 3 → 8. Optimistic setItems → rerender.
    const bumped = [{ ...initialItems[0], effort_score: 8 as const }];
    rerender({ items: bumped });

    // No additional fetch — the overlay re-derives synchronously.
    expect(mockFetch).toHaveBeenCalledTimes(1);

    // Delta is +5 → upcoming 3+5 = 8. Still under capacity 12.
    expect(result.current.forecast?.upcoming_effort).toBe(8);
    expect(result.current.forecast?.burnout_risk).toBe(false);

    // Push harder: 3 items × 8 pts = 24 visible, delta +21 → upcoming 24.
    const overloaded = [
      makeItem({ id: "x", due_date: tomorrow, effort_score: 8 }),
      makeItem({ id: "y", due_date: tomorrow, effort_score: 8 }),
      makeItem({ id: "z", due_date: tomorrow, effort_score: 8 }),
    ];
    rerender({ items: overloaded });

    expect(mockFetch).toHaveBeenCalledTimes(1); // still no refetch
    expect(result.current.forecast?.upcoming_effort).toBe(24);
    expect(result.current.forecast?.burnout_risk).toBe(true); // 24 > 12
  });

  it("refresh() re-baselines so delta resets to zero", async () => {
    mockFetch.mockResolvedValue({
      velocity_ema: 10,
      velocity_std_dev: 0,
      upcoming_effort: 5,
      capacity_upper_bound: 10,
      probability_of_success: 1,
      burnout_risk: false,
      weeks_analysed: 12,
      weekly_series: Array(12).fill(10),
    } satisfies VelocityForecast);

    const items = [makeItem({ id: "x", due_date: tomorrow, effort_score: 5 })];

    const { result, rerender } = renderHook(
      ({ items }) => useVelocityForecast(true, items),
      { initialProps: { items } },
    );

    await act(async () => {});
    expect(result.current.forecast?.upcoming_effort).toBe(5);

    // Bump → delta +3.
    rerender({
      items: [makeItem({ id: "x", due_date: tomorrow, effort_score: 8 })],
    });
    expect(result.current.forecast?.upcoming_effort).toBe(8);

    // Server eventually catches up — next fetch returns upcoming: 8.
    mockFetch.mockResolvedValue({
      velocity_ema: 10,
      velocity_std_dev: 0,
      upcoming_effort: 8,
      capacity_upper_bound: 10,
      probability_of_success: 1,
      burnout_risk: false,
      weeks_analysed: 12,
      weekly_series: Array(12).fill(10),
    } satisfies VelocityForecast);

    await act(async () => {
      await result.current.refresh();
    });

    // Baseline resnapped to 8 (current client sum), server says 8, delta = 0.
    expect(result.current.forecast?.upcoming_effort).toBe(8);
  });
});
