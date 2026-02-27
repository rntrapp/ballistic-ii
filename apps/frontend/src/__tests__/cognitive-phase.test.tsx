import { render, screen } from "@testing-library/react";
import {
  minutesUntilDeepWork,
  sortByCognitivePhase,
} from "@/lib/cognitiveSort";
import { CognitiveWave } from "@/components/CognitiveWave";
import type { CognitivePhaseSnapshot, Item } from "@/types";

/**
 * Minimal Item fixture factory. Only fields the sort touches are varied;
 * everything else is boilerplate kept identical across instances.
 */
function mkItem(id: string, cognitiveLoad: number | null): Item {
  return {
    id,
    user_id: "user-1",
    assignee_id: null,
    project_id: null,
    title: `Task ${id}`,
    description: null,
    assignee_notes: null,
    status: "todo",
    position: 0,
    cognitive_load: cognitiveLoad,
    scheduled_date: null,
    due_date: null,
    completed_at: null,
    recurrence_rule: null,
    recurrence_parent_id: null,
    recurrence_strategy: null,
    is_recurring_template: false,
    is_recurring_instance: false,
    is_assigned: false,
    is_delegated: false,
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
    deleted_at: null,
  };
}

describe("sortByCognitivePhase", () => {
  test("orders high-load first during peak", () => {
    const input = [mkItem("a", 2), mkItem("b", 9), mkItem("c", 5)];
    const out = sortByCognitivePhase(input, "peak");
    expect(out.map((i) => i.id)).toEqual(["b", "c", "a"]);
  });

  test("orders low-load first during trough", () => {
    const input = [mkItem("a", 8), mkItem("b", 1), mkItem("c", 4)];
    const out = sortByCognitivePhase(input, "trough");
    expect(out.map((i) => i.id)).toEqual(["b", "c", "a"]);
  });

  test("is a no-op during recovery", () => {
    const input = [mkItem("a", 9), mkItem("b", 1), mkItem("c", 5)];
    const out = sortByCognitivePhase(input, "recovery");
    expect(out).toBe(input); // same reference — not even a copy
  });

  test("is a no-op when phase is undefined", () => {
    const input = [mkItem("a", 9), mkItem("b", 1)];
    const out = sortByCognitivePhase(input, undefined);
    expect(out).toBe(input);
  });

  test("treats null cognitive_load as neutral (5)", () => {
    // During peak: 9 > null(=5) > 2
    const input = [mkItem("a", 2), mkItem("b", null), mkItem("c", 9)];
    const out = sortByCognitivePhase(input, "peak");
    expect(out.map((i) => i.id)).toEqual(["c", "b", "a"]);
  });

  test("is stable: preserves original order within equal-load tier", () => {
    // All three have the same effective load (null → 5). During peak the
    // sort is active but since loads are equal, original order must hold.
    const input = [
      mkItem("first", null),
      mkItem("second", 5),
      mkItem("third", null),
    ];
    const out = sortByCognitivePhase(input, "peak");
    expect(out.map((i) => i.id)).toEqual(["first", "second", "third"]);
  });

  test("does not mutate the input array", () => {
    const input = [mkItem("a", 1), mkItem("b", 9)];
    const snapshot = [...input];
    sortByCognitivePhase(input, "peak");
    expect(input).toEqual(snapshot);
  });
});

describe("minutesUntilDeepWork", () => {
  /** Build a snapshot where the next peak is `mins` minutes after `now`. */
  function snapshotPeakingIn(mins: number, now: Date): CognitivePhaseSnapshot {
    return {
      has_profile: true,
      phase: "peak", // deliberately NOT "recovery" — spec scenario has the
      // user already in the Peak classification band at 15 min out on a
      // 100-min cycle, and the banner must still fire.
      dominant_cycle_minutes: 100,
      next_peak_at: new Date(now.getTime() + mins * 60_000).toISOString(),
      confidence: 0.8,
      amplitude_fraction: 0.7,
      sample_count: 50,
    };
  }

  test("returns 15 for the exact 2:00 PM → 2:15 PM spec scenario", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    const snap = snapshotPeakingIn(15, now);
    expect(minutesUntilDeepWork(snap, now)).toBe(15);
  });

  test("triggers regardless of current phase classification", () => {
    // Key regression: old implementation gated on phase === "recovery" and
    // would SUPPRESS the banner when the user was already classified as Peak
    // — which happens at 15 min out on a 100-min cycle.
    const now = new Date("2025-06-01T14:00:00Z");
    for (const phase of ["peak", "trough", "recovery"] as const) {
      const snap = { ...snapshotPeakingIn(10, now), phase };
      expect(minutesUntilDeepWork(snap, now)).toBe(10);
    }
  });

  test("returns null when next peak is more than 15 minutes away", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    const snap = snapshotPeakingIn(16, now);
    expect(minutesUntilDeepWork(snap, now)).toBeNull();
  });

  test("returns the countdown (rounded) when within the window", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    expect(minutesUntilDeepWork(snapshotPeakingIn(1, now), now)).toBe(1);
    expect(minutesUntilDeepWork(snapshotPeakingIn(7, now), now)).toBe(7);
    expect(minutesUntilDeepWork(snapshotPeakingIn(15, now), now)).toBe(15);
  });

  test("returns null when next_peak_at has already passed", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    const snap = snapshotPeakingIn(-5, now);
    expect(minutesUntilDeepWork(snap, now)).toBeNull();
  });

  test("returns null when has_profile is false", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    const snap: CognitivePhaseSnapshot = {
      has_profile: false,
      message: "Insufficient data",
    };
    expect(minutesUntilDeepWork(snap, now)).toBeNull();
  });

  test("returns null when snapshot is null", () => {
    expect(minutesUntilDeepWork(null)).toBeNull();
  });

  test("returns null when next_peak_at is absent", () => {
    const now = new Date("2025-06-01T14:00:00Z");
    const snap: CognitivePhaseSnapshot = {
      has_profile: true,
      phase: "recovery",
      dominant_cycle_minutes: 100,
      confidence: 0.8,
      sample_count: 50,
      // no next_peak_at
    };
    expect(minutesUntilDeepWork(snap, now)).toBeNull();
  });
});

describe("CognitiveWave", () => {
  test("renders canvas element when profile exists", () => {
    const snapshot: CognitivePhaseSnapshot = {
      has_profile: true,
      phase: "peak",
      dominant_cycle_minutes: 100,
      next_peak_at: new Date(Date.now() + 30 * 60_000).toISOString(),
      confidence: 0.8,
      amplitude_fraction: 0.6,
      sample_count: 42,
    };
    render(<CognitiveWave snapshot={snapshot} events={[]} />);
    expect(screen.getByRole("img")).toBeInTheDocument();
    expect(screen.getByRole("img").getAttribute("aria-label")).toMatch(/peak/);
  });

  test("shows no-data message when has_profile is false", () => {
    const snapshot: CognitivePhaseSnapshot = {
      has_profile: false,
      message: "Insufficient data — complete at least 10 tasks.",
    };
    render(<CognitiveWave snapshot={snapshot} events={[]} />);
    expect(
      screen.getByText(/Insufficient data — complete at least 10 tasks\./),
    ).toBeInTheDocument();
  });

  test("shows fallback message when snapshot is null", () => {
    render(<CognitiveWave snapshot={null} events={[]} />);
    expect(
      screen.getByText(/Complete more tasks to build your cognitive profile/),
    ).toBeInTheDocument();
  });
});
