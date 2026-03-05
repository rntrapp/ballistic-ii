/**
 * User-scenario coverage for the spec's core requirement:
 *
 *   "Changing effort_score 1→8 must instantly shift the probability chart,
 *    no server round trip."
 *
 * forecast.test.ts proves the maths. This file proves the wiring:
 * page.tsx → adjustForecast → reforecast → CapacityDashboard, all
 * synchronous, with the network deliberately held open.
 */

import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Home from "@/app/page";
import { AuthProvider } from "@/contexts/AuthContext";
import type { Item, VelocityForecast } from "@/types";

// ─── Clock ───────────────────────────────────────────────────────────────
// loadContribution() in page.tsx uses real Date. Pin it so "due in 3 days"
// stays inside the 7-day window regardless of when CI runs.
const FROZEN_NOW = new Date("2026-03-05T12:00:00Z");
const iso = (daysFromNow: number) =>
  new Date(FROZEN_NOW.getTime() + daysFromNow * 86_400_000)
    .toISOString()
    .slice(0, 10);

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ["setTimeout", "clearTimeout"] });
  jest.setSystemTime(FROZEN_NOW);
});
afterEach(() => {
  jest.useRealTimers();
});

// ─── Feature flag ────────────────────────────────────────────────────────
// Dashboard is gated behind `dates` and the effort picker lives inside
// the same conditional in ItemForm. Mock the hook directly — cheaper
// than threading it through AuthProvider/fetchUser.
jest.mock("@/hooks/useFeatureFlags", () => ({
  useFeatureFlags: () => ({
    dates: true,
    delegation: false,
    setFlag: jest.fn(),
    loaded: true,
  }),
}));

// ─── Auth ────────────────────────────────────────────────────────────────
jest.mock("@/lib/auth", () => ({
  getToken: jest.fn(() => "test-token"),
  getStoredUser: jest.fn(() => ({
    id: "user-1",
    name: "Test User",
    email: "test@example.com",
    email_verified_at: "2025-01-01T00:00:00Z",
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
  })),
  setToken: jest.fn(),
  clearToken: jest.fn(),
  setStoredUser: jest.fn(),
  isAuthenticated: jest.fn(() => true),
  login: jest.fn(),
  register: jest.fn(),
  logout: jest.fn(),
  getAuthHeaders: jest.fn(() => ({
    "Content-Type": "application/json",
    Accept: "application/json",
    Authorization: "Bearer test-token",
  })),
  AuthError: class extends Error {
    errors: Record<string, string[]> = {};
  },
}));

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: jest.fn() }),
}));

// ─── Fixtures ────────────────────────────────────────────────────────────
// Velocity ≈ 3 pts/wk (three completed weeks at effort 3). Load 1 is
// comfortably under capacity (3 + σ); load 8 is well over. That's the
// flip we want to observe.
const SEED_FORECAST: VelocityForecast = {
  velocity: 3,
  std_dev: 0.5,
  capacity_upper: 3.5,
  upcoming_load: 1,
  burnout_risk: false,
  probability_of_success: 0.9999,
  weekly_history: [3, 3, 3],
  sample_weeks: 3,
};

const SEED_ITEM: Item = {
  id: "item-1",
  user_id: "user-1",
  assignee_id: null,
  project_id: null,
  title: "Launch prep",
  description: null,
  status: "todo",
  position: 0,
  effort_score: 1,
  scheduled_date: null,
  due_date: iso(3), // inside the 7-day horizon → contributes to load
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
};

// Controllable updateItem — each test wires its own promise.
const mockUpdateItem = jest.fn();
const mockCreateItem = jest.fn();
const mockFetchVelocityForecast = jest.fn();

jest.mock("@/lib/api", () => ({
  fetchVelocityForecast: (...a: unknown[]) => mockFetchVelocityForecast(...a),
  fetchProjects: jest.fn().mockResolvedValue([]),
  createProject: jest.fn(),
  fetchItems: jest.fn().mockImplementation((params) => {
    if (params?.assigned_to_me || params?.delegated) return Promise.resolve([]);
    return Promise.resolve([SEED_ITEM]);
  }),
  createItem: (...a: unknown[]) => mockCreateItem(...a),
  updateItem: (...a: unknown[]) => mockUpdateItem(...a),
  updateStatus: jest.fn(() => Promise.resolve(SEED_ITEM)),
  deleteItem: jest.fn().mockResolvedValue({ ok: true }),
  moveItem: jest.fn(() => Promise.resolve([SEED_ITEM])),
  saveItemOrder: jest.fn(() => Promise.resolve([SEED_ITEM])),
  fetchUser: jest.fn().mockResolvedValue({
    id: "user-1",
    name: "Test User",
    email: "test@example.com",
    phone: null,
    notes: null,
    feature_flags: { dates: true, delegation: false },
    email_verified_at: "2025-01-01T00:00:00Z",
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
  }),
  updateUser: jest.fn(),
}));

// ─── Helpers ─────────────────────────────────────────────────────────────
const renderPage = () =>
  render(
    <AuthProvider>
      <Home />
    </AuthProvider>,
  );

/** Find the effort-picker button by its visible Fibonacci label. Scoped
 *  to the modal so we don't accidentally grab a stray "8" elsewhere. */
async function clickEffort(
  user: ReturnType<typeof userEvent.setup>,
  score: number,
) {
  const heading = screen.getByRole("heading", { name: /edit task/i });
  const modal = heading.closest("div")!.parentElement!;
  const btn = within(modal)
    .getAllByRole("button")
    .find(
      (b) => b.textContent === String(score) && b.hasAttribute("aria-pressed"),
    );
  expect(btn).toBeDefined();
  await user.click(btn!);
}

// ─────────────────────────────────────────────────────────────────────────

describe("Forecast reactivity — user scenario", () => {
  beforeEach(() => {
    mockFetchVelocityForecast.mockReset().mockResolvedValue(SEED_FORECAST);
    mockUpdateItem.mockReset();
    mockCreateItem.mockReset();
  });

  test("bumping effort 1→8 flips the dashboard to Burnout Risk before the PATCH resolves", async () => {
    // updateItem never resolves — so anything we observe below is
    // provably from the optimistic path, not a server response.
    mockUpdateItem.mockReturnValue(new Promise(() => {}));

    const user = userEvent.setup({ advanceTimers: jest.advanceTimersByTime });
    renderPage();

    // ─── Before ─────────────────────────────────────────────────────────
    const status = await screen.findByRole("status");
    expect(within(status).getByText("On Track")).toBeInTheDocument();
    expect(status).toHaveTextContent("1 / 3.5 pts this week");
    expect(status).toHaveTextContent("100% likely");

    // ─── Act ────────────────────────────────────────────────────────────
    await user.click(screen.getByText("Launch prep"));
    await screen.findByRole("heading", { name: /edit task/i });
    await clickEffort(user, 8);
    await user.click(screen.getByRole("button", { name: "Save" }));

    // ─── After — synchronous, PATCH still pending ───────────────────────
    expect(mockUpdateItem).toHaveBeenCalledTimes(1);
    expect(mockUpdateItem).toHaveBeenCalledWith(
      "item-1",
      expect.objectContaining({ effort_score: 8 }),
    );

    // Dashboard must have flipped already. No waitFor — this is the point.
    const statusAfter = screen.getByRole("status");
    expect(within(statusAfter).getByText("Burnout Risk")).toBeInTheDocument();
    expect(within(statusAfter).queryByText("On Track")).not.toBeInTheDocument();

    // Load went 1 → 8 (delta +7 from the effort bump).
    expect(statusAfter).toHaveTextContent("8 / 3.5 pts this week");

    // Probability dropped from ~100% to ~0% (load 8 vs μ=3, σ=0.5 → z=10).
    expect(statusAfter).toHaveTextContent("0% likely");

    // And crucially: no refetch. Server was consulted exactly once, at mount.
    expect(mockFetchVelocityForecast).toHaveBeenCalledTimes(1);
  });

  test("dashboard reverts when the API rejects the effort change", async () => {
    let reject!: (e: Error) => void;
    mockUpdateItem.mockReturnValue(
      new Promise((_, r) => {
        reject = r;
      }),
    );
    jest.spyOn(console, "error").mockImplementation(() => {});

    const user = userEvent.setup({ advanceTimers: jest.advanceTimersByTime });
    renderPage();

    await screen.findByRole("status");
    expect(screen.getByText("On Track")).toBeInTheDocument();

    await user.click(screen.getByText("Launch prep"));
    await screen.findByRole("heading", { name: /edit task/i });
    await clickEffort(user, 8);
    await user.click(screen.getByRole("button", { name: "Save" }));

    // Optimistic flip happened.
    expect(screen.getByText("Burnout Risk")).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("8 / 3.5 pts");

    // Server says no.
    reject(new Error("500"));

    // Dashboard rolls back: label, load, probability all restored.
    await waitFor(() => {
      expect(screen.getByText("On Track")).toBeInTheDocument();
    });
    const status = screen.getByRole("status");
    expect(status).toHaveTextContent("1 / 3.5 pts this week");
    expect(status).toHaveTextContent("100% likely");
    expect(within(status).queryByText("Burnout Risk")).not.toBeInTheDocument();

    // Still no refetch — revert is also purely local.
    expect(mockFetchVelocityForecast).toHaveBeenCalledTimes(1);
  });

  test("clearing due_date drops the item out of the load window", async () => {
    mockUpdateItem.mockReturnValue(new Promise(() => {}));

    // Start overloaded this time: effort 8 already in place.
    mockFetchVelocityForecast.mockResolvedValue({
      ...SEED_FORECAST,
      upcoming_load: 8,
      burnout_risk: true,
      probability_of_success: 0,
    });
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { fetchItems } = require("@/lib/api");
    fetchItems.mockImplementation(
      (p: { assigned_to_me?: boolean; delegated?: boolean }) =>
        p?.assigned_to_me || p?.delegated
          ? Promise.resolve([])
          : Promise.resolve([
              { ...SEED_ITEM, effort_score: 8, due_date: iso(3) },
            ]),
    );

    const user = userEvent.setup({ advanceTimers: jest.advanceTimersByTime });
    renderPage();

    const status = await screen.findByRole("status");
    expect(within(status).getByText("Burnout Risk")).toBeInTheDocument();
    expect(status).toHaveTextContent("8 / 3.5 pts");

    // Open, clear the due date, save.
    await user.click(screen.getByText("Launch prep"));
    await screen.findByRole("heading", { name: /edit task/i });

    const heading = screen.getByRole("heading", { name: /edit task/i });
    const modal = heading.closest("div")!.parentElement!;
    const dateInputs = within(modal)
      .getAllByDisplayValue(iso(3))
      .filter(
        (el): el is HTMLInputElement => el.getAttribute("type") === "date",
      );
    // due_date is the second date field (scheduled_date is first, and empty).
    // But we found it by value so there's only one match here.
    expect(dateInputs).toHaveLength(1);
    await user.clear(dateInputs[0]);
    await user.click(screen.getByRole("button", { name: "Save" }));

    // Item no longer contributes → load drops by 8 → 0 → On Track.
    const after = screen.getByRole("status");
    expect(within(after).getByText("On Track")).toBeInTheDocument();
    expect(after).toHaveTextContent("0 / 3.5 pts");
    expect(mockFetchVelocityForecast).toHaveBeenCalledTimes(1);
  });

  test("editing a task due outside the horizon is a no-op on the dashboard", async () => {
    mockUpdateItem.mockReturnValue(new Promise(() => {}));

    // Item due 20 days out — beyond the 7-day window. loadContribution
    // returns 0 before and after, so delta is 0 and adjustForecast
    // short-circuits.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { fetchItems } = require("@/lib/api");
    fetchItems.mockImplementation(
      (p: { assigned_to_me?: boolean; delegated?: boolean }) =>
        p?.assigned_to_me || p?.delegated
          ? Promise.resolve([])
          : Promise.resolve([{ ...SEED_ITEM, due_date: iso(20) }]),
    );

    const user = userEvent.setup({ advanceTimers: jest.advanceTimersByTime });
    renderPage();

    const status = await screen.findByRole("status");
    const snapshot = status.textContent;
    expect(within(status).getByText("On Track")).toBeInTheDocument();

    await user.click(screen.getByText("Launch prep"));
    await screen.findByRole("heading", { name: /edit task/i });
    await clickEffort(user, 8);
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(screen.getByRole("status").textContent).toBe(snapshot);
  });
});
