import { render, screen, waitFor } from "@testing-library/react";
import type { Item, VelocityForecast } from "@/types";
import {
  CapacityDashboard,
  UNIT,
  decimalToMicros,
  intToMicros,
  microsToDecimal,
  divMicros,
  deriveForecastMetrics,
  computeLocalUpcomingEffort,
} from "@/components/CapacityDashboard";

// ─────────────────────────────────────────────────────────────────────────────
// Fixed-point arithmetic (BigInt, scale=6) — these helpers mirror backend
// BCMath. Precision here is the whole point of the feature: a single ULP of
// IEEE-754 drift could flip the burnout flag. Every test compares BigInt
// values exactly; no floating-point equality anywhere.
// ─────────────────────────────────────────────────────────────────────────────

describe("fixed-point BigInt helpers", () => {
  describe("decimalToMicros", () => {
    test("parses backend BCMath output (6-dp padded)", () => {
      expect(decimalToMicros("10.000000")).toBe(BigInt(10_000_000));
      expect(decimalToMicros("0.300000")).toBe(BigInt(300_000));
      expect(decimalToMicros("0.400000")).toBe(BigInt(400_000));
    });

    test("parses unpadded decimals", () => {
      expect(decimalToMicros("0.3")).toBe(BigInt(300_000));
      expect(decimalToMicros("7")).toBe(BigInt(7_000_000));
      expect(decimalToMicros(".5")).toBe(BigInt(500_000));
    });

    test("parses zero", () => {
      expect(decimalToMicros("0")).toBe(BigInt(0));
      expect(decimalToMicros("0.000000")).toBe(BigInt(0));
    });

    test("truncates fractional digits beyond scale=6", () => {
      // Backend sends exactly 6 dp, but be defensive.
      expect(decimalToMicros("0.1234567")).toBe(BigInt(123_456));
    });

    test("parses negative values", () => {
      expect(decimalToMicros("-1.5")).toBe(BigInt(-1_500_000));
    });
  });

  describe("intToMicros", () => {
    test("lifts integers to micro-units", () => {
      expect(intToMicros(0)).toBe(BigInt(0));
      expect(intToMicros(1)).toBe(UNIT);
      expect(intToMicros(25)).toBe(BigInt(25_000_000));
    });
  });

  describe("microsToDecimal", () => {
    test("renders with trailing zeros trimmed", () => {
      expect(microsToDecimal(BigInt(10_000_000))).toBe("10");
      expect(microsToDecimal(BigInt(10_500_000))).toBe("10.5");
      expect(microsToDecimal(BigInt(300_000))).toBe("0.3");
      expect(microsToDecimal(BigInt(0))).toBe("0");
    });

    test("preserves interior zeros", () => {
      expect(microsToDecimal(BigInt(1_000_001))).toBe("1.000001");
      expect(microsToDecimal(BigInt(100_010))).toBe("0.10001");
    });

    test("round-trips decimalToMicros", () => {
      const cases = ["7", "0.3", "10.5", "0.000001", "123.456789"];
      for (const c of cases) {
        expect(microsToDecimal(decimalToMicros(c))).toBe(c);
      }
    });
  });

  describe("divMicros", () => {
    test("matches backend bcdiv truncation semantics", () => {
      // velocity 10 ÷ load 25 = 0.4 exactly
      expect(divMicros(intToMicros(10), intToMicros(25))).toBe(BigInt(400_000));
      // velocity 1 ÷ load 3 = 0.333333 (truncated, not rounded)
      expect(divMicros(intToMicros(1), intToMicros(3))).toBe(BigInt(333_333));
      // velocity 2 ÷ load 3 = 0.666666 (truncated, not 0.666667)
      expect(divMicros(intToMicros(2), intToMicros(3))).toBe(BigInt(666_666));
    });

    test("divides by unit to recover original scaled value", () => {
      expect(divMicros(intToMicros(10), UNIT)).toBe(intToMicros(10));
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Burnout / probability derivation — precision-critical decision logic.
// ─────────────────────────────────────────────────────────────────────────────

describe("deriveForecastMetrics", () => {
  test("acceptance scenario: velocity 10, load 25 ⇒ burnout, 40% success", () => {
    const { burnoutRisk, probabilityMicros } = deriveForecastMetrics(
      "10.000000",
      25,
    );
    expect(burnoutRisk).toBe(true);
    expect(probabilityMicros).toBe(BigInt(400_000)); // exactly 0.4
  });

  test("load exactly equals velocity ⇒ NOT burnout (boundary is strict >)", () => {
    const { burnoutRisk, probabilityMicros } = deriveForecastMetrics(
      "10.000000",
      10,
    );
    expect(burnoutRisk).toBe(false);
    expect(probabilityMicros).toBe(UNIT); // ratio = 1, clamped
  });

  test("one micro-unit above threshold ⇒ NOT burnout (upcoming is integer)", () => {
    // velocity = 10.000001, load = 10 ⇒ 10_000_000 < 10_000_001
    // Float comparison of 10.000001 vs 10.0 would be fine here, but this
    // proves the comparison is exact at scale=6 granularity.
    const { burnoutRisk } = deriveForecastMetrics("10.000001", 10);
    expect(burnoutRisk).toBe(false);
  });

  test("velocity one micro-unit BELOW load ⇒ burnout", () => {
    // velocity = 9.999999, load = 10 ⇒ 10_000_000 > 9_999_999
    const { burnoutRisk } = deriveForecastMetrics("9.999999", 10);
    expect(burnoutRisk).toBe(true);
  });

  test("fractional velocity vs integer load — exact at threshold", () => {
    // velocity = 7.5, load = 8 ⇒ burnout, probability = 7.5/8 = 0.9375 exactly
    const { burnoutRisk, probabilityMicros } = deriveForecastMetrics("7.5", 8);
    expect(burnoutRisk).toBe(true);
    expect(probabilityMicros).toBe(BigInt(937_500));
  });

  test("no load ⇒ trivially achievable", () => {
    expect(deriveForecastMetrics("5.0", 0).probabilityMicros).toBe(UNIT);
    expect(deriveForecastMetrics("0", 0).probabilityMicros).toBe(UNIT);
  });

  test("no velocity but non-zero load ⇒ impossible", () => {
    const { burnoutRisk, probabilityMicros } = deriveForecastMetrics("0", 5);
    expect(burnoutRisk).toBe(true);
    expect(probabilityMicros).toBe(BigInt(0));
  });

  test("load < velocity ⇒ probability clamped to 1", () => {
    const { burnoutRisk, probabilityMicros } = deriveForecastMetrics(
      "20.000000",
      5,
    );
    expect(burnoutRisk).toBe(false);
    expect(probabilityMicros).toBe(UNIT);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Upcoming-effort local computation — must exactly mirror backend
// VelocityForecastingService::upcomingEffort.
// ─────────────────────────────────────────────────────────────────────────────

describe("computeLocalUpcomingEffort", () => {
  const today = new Date(2026, 2, 5); // 2026-03-05 (month is 0-indexed)

  function mkItem(overrides: Partial<Item>): Item {
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
      created_at: "2026-03-01T00:00:00Z",
      updated_at: "2026-03-01T00:00:00Z",
      deleted_at: null,
      ...overrides,
    };
  }

  test("sums open items due in the 7-day window", () => {
    const items = [
      mkItem({ due_date: "2026-03-05", effort_score: 5 }), // today (day 1)
      mkItem({ due_date: "2026-03-08", effort_score: 8 }), // day 4
      mkItem({ due_date: "2026-03-11", effort_score: 2 }), // day 7 (today+6, upper bound)
    ];
    expect(computeLocalUpcomingEffort(items, today)).toBe(15);
  });

  test("window is exactly 7 days: today+7 is EXCLUDED (8th calendar day)", () => {
    const items = [
      mkItem({ due_date: "2026-03-11", effort_score: 3 }), // today+6 — IN
      mkItem({ due_date: "2026-03-12", effort_score: 5 }), // today+7 — OUT
    ];
    expect(computeLocalUpcomingEffort(items, today)).toBe(3);
  });

  test("excludes items due before today", () => {
    const items = [mkItem({ due_date: "2026-03-04", effort_score: 8 })];
    expect(computeLocalUpcomingEffort(items, today)).toBe(0);
  });

  test("excludes done and wontdo statuses", () => {
    const items = [
      mkItem({ due_date: "2026-03-06", effort_score: 5, status: "done" }),
      mkItem({ due_date: "2026-03-06", effort_score: 3, status: "wontdo" }),
      mkItem({ due_date: "2026-03-06", effort_score: 2, status: "doing" }),
    ];
    expect(computeLocalUpcomingEffort(items, today)).toBe(2);
  });

  test("excludes items without a due_date", () => {
    const items = [mkItem({ due_date: null, effort_score: 8 })];
    expect(computeLocalUpcomingEffort(items, today)).toBe(0);
  });

  test("defaults missing effort_score to 1", () => {
    const items = [
      mkItem({ due_date: "2026-03-06", effort_score: undefined as never }),
    ];
    expect(computeLocalUpcomingEffort(items, today)).toBe(1);
  });

  test("returns 0 for empty item list", () => {
    expect(computeLocalUpcomingEffort([], today)).toBe(0);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Component integration — optimistic reactivity. Proves that editing the
// `items` prop (without any server round-trip) flips the burnout flag.
// ─────────────────────────────────────────────────────────────────────────────

const mockForecast: VelocityForecast = {
  weekly_velocity: "10.000000",
  upcoming_effort: 0, // server value — ignored by the optimistic path
  burnout_risk: false,
  success_probability: "1.000000",
  weekly_history: [
    { week_start: "2026-02-02", effort: 10 },
    { week_start: "2026-02-09", effort: 10 },
    { week_start: "2026-02-16", effort: 10 },
    { week_start: "2026-02-23", effort: 10 },
  ],
  lookback_weeks: 4,
  alpha: "0.3",
};

jest.mock("@/lib/api", () => ({
  fetchVelocity: jest.fn(() => Promise.resolve(mockForecast)),
}));

describe("CapacityDashboard component", () => {
  function mkItem(overrides: Partial<Item>): Item {
    return {
      id: overrides.id ?? "x",
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
      created_at: "2026-03-01T00:00:00Z",
      updated_at: "2026-03-01T00:00:00Z",
      deleted_at: null,
      ...overrides,
    };
  }

  beforeAll(() => {
    jest.useFakeTimers().setSystemTime(new Date(2026, 2, 5)); // 2026-03-05
  });

  afterAll(() => {
    jest.useRealTimers();
  });

  test("no burnout badge when load ≤ velocity", async () => {
    // 8 pts scheduled vs velocity 10 — healthy.
    const items = [
      mkItem({ id: "a", due_date: "2026-03-07", effort_score: 8 }),
    ];

    render(<CapacityDashboard items={items} lookbackWeeks={4} />);

    await waitFor(() => {
      expect(screen.getByRole("region", { name: /capacity forecast/i }));
    });
    expect(screen.queryByRole("alert")).toBeNull();
  });

  test("burnout badge appears when load > velocity (acceptance scenario)", async () => {
    // 25 pts scheduled vs velocity 10 — burnout.
    const items = [
      mkItem({ id: "a", due_date: "2026-03-06", effort_score: 8 }),
      mkItem({ id: "b", due_date: "2026-03-07", effort_score: 8 }),
      mkItem({ id: "c", due_date: "2026-03-08", effort_score: 8 }),
      mkItem({ id: "d", due_date: "2026-03-09", effort_score: 1 }),
    ];

    render(<CapacityDashboard items={items} lookbackWeeks={4} />);

    await waitFor(() => {
      expect(screen.getByRole("alert")).toHaveTextContent(/burnout risk/i);
    });

    // Probability shown as 40% (velocity 10 / load 25 = 0.4 exactly).
    const progressbar = screen.getByRole("progressbar");
    expect(progressbar).toHaveAttribute("aria-valuenow", "40");
  });

  test("optimistic update: changing effort 1→8 flips burnout instantly (no refetch)", async () => {
    // Start at 18 pts (healthy: 8+8+1+1 vs velocity 10). Wait, 18 > 10 = burnout.
    // Need to start BELOW threshold. velocity = 10, so start with ≤ 10.
    const before: Item[] = [
      mkItem({ id: "a", due_date: "2026-03-06", effort_score: 8 }),
      mkItem({ id: "b", due_date: "2026-03-07", effort_score: 1 }),
    ]; // total 9 — healthy

    const { rerender } = render(
      <CapacityDashboard items={before} lookbackWeeks={4} />,
    );

    await waitFor(() => {
      expect(screen.getByRole("region", { name: /capacity forecast/i }));
    });
    expect(screen.queryByRole("alert")).toBeNull();

    // Bump item b from 1 → 8. Total 16 > velocity 10 ⇒ burnout.
    // No server call — pure prop change.
    const after: Item[] = [
      mkItem({ id: "a", due_date: "2026-03-06", effort_score: 8 }),
      mkItem({ id: "b", due_date: "2026-03-07", effort_score: 8 }),
    ];
    rerender(<CapacityDashboard items={after} lookbackWeeks={4} />);

    // Flag flips synchronously with the re-render.
    expect(screen.getByRole("alert")).toHaveTextContent(/burnout risk/i);
  });

  test("optimistic update: dragging task into this week flips burnout", async () => {
    // Item due next month — not counted.
    const outside: Item[] = [
      mkItem({ id: "a", due_date: "2026-03-06", effort_score: 8 }),
      mkItem({ id: "b", due_date: "2026-04-15", effort_score: 5 }),
    ]; // only 8 counted — healthy

    const { rerender } = render(
      <CapacityDashboard items={outside} lookbackWeeks={4} />,
    );

    await waitFor(() => {
      expect(screen.getByRole("region", { name: /capacity forecast/i }));
    });
    expect(screen.queryByRole("alert")).toBeNull();

    // Move b's due_date into this week. Total 8+5=13 > 10 ⇒ burnout.
    const inside: Item[] = [
      mkItem({ id: "a", due_date: "2026-03-06", effort_score: 8 }),
      mkItem({ id: "b", due_date: "2026-03-08", effort_score: 5 }),
    ];
    rerender(<CapacityDashboard items={inside} lookbackWeeks={4} />);

    expect(screen.getByRole("alert")).toHaveTextContent(/burnout risk/i);
  });
});
