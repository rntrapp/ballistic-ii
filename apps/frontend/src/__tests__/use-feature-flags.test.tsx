import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useFeatureFlags } from "@/hooks/useFeatureFlags";
import { AuthContext } from "@/contexts/AuthContext";
import type { User } from "@/types";

function Harness() {
  const { setFlag } = useFeatureFlags();

  return (
    <button type="button" onClick={() => void setFlag("dates", true)}>
      Toggle dates
    </button>
  );
}

function FlagsDisplay() {
  const { dates, delegation, userFlags, available } = useFeatureFlags();

  return (
    <div>
      <span data-testid="eff-dates">{String(dates)}</span>
      <span data-testid="eff-delegation">{String(delegation)}</span>
      <span data-testid="user-dates">{String(userFlags.dates)}</span>
      <span data-testid="user-delegation">{String(userFlags.delegation)}</span>
      <span data-testid="avail-dates">{String(available.dates)}</span>
      <span data-testid="avail-delegation">{String(available.delegation)}</span>
    </div>
  );
}

function renderWithAuth(mockUser: User) {
  return render(
    <AuthContext.Provider
      value={{
        user: mockUser,
        isAuthenticated: true,
        isLoading: false,
        login: jest.fn(),
        register: jest.fn(),
        logout: jest.fn(),
        refreshUser: jest.fn(),
        updateUser: jest.fn(),
      }}
    >
      <FlagsDisplay />
    </AuthContext.Provider>,
  );
}

describe("useFeatureFlags", () => {
  test("sends only the changed flag key to avoid multi-tab race conditions", async () => {
    const updateUser = jest.fn().mockResolvedValue(undefined);
    const user = userEvent.setup();

    const mockUser: User = {
      id: "user-1",
      name: "Test User",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: {
        dates: false,
        delegation: false,
      },
      email_verified_at: "2025-01-01T00:00:00Z",
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    render(
      <AuthContext.Provider
        value={{
          user: mockUser,
          isAuthenticated: true,
          isLoading: false,
          login: jest.fn(),
          register: jest.fn(),
          logout: jest.fn(),
          refreshUser: jest.fn(),
          updateUser,
        }}
      >
        <Harness />
      </AuthContext.Provider>,
    );

    await user.click(screen.getByRole("button", { name: "Toggle dates" }));

    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledWith({
        feature_flags: { dates: true },
      });
    });
  });

  test("effective false when global disabled but user enabled", () => {
    const mockUser: User = {
      id: "user-1",
      name: "Test",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: { dates: true, delegation: true },
      available_feature_flags: {
        dates: false,
        delegation: true,
      },
      email_verified_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    renderWithAuth(mockUser);

    expect(screen.getByTestId("eff-dates")).toHaveTextContent("false");
    expect(screen.getByTestId("eff-delegation")).toHaveTextContent("true");
  });

  test("effective false when user disabled but global enabled", () => {
    const mockUser: User = {
      id: "user-1",
      name: "Test",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: { dates: false, delegation: true },
      available_feature_flags: {
        dates: true,
        delegation: true,
      },
      email_verified_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    renderWithAuth(mockUser);

    expect(screen.getByTestId("eff-dates")).toHaveTextContent("false");
    expect(screen.getByTestId("eff-delegation")).toHaveTextContent("true");
  });

  test("effective true only when both user and global are true", () => {
    const mockUser: User = {
      id: "user-1",
      name: "Test",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: { dates: true, delegation: true },
      available_feature_flags: {
        dates: true,
        delegation: true,
      },
      email_verified_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    renderWithAuth(mockUser);

    expect(screen.getByTestId("eff-dates")).toHaveTextContent("true");
    expect(screen.getByTestId("eff-delegation")).toHaveTextContent("true");
  });

  test("available defaults to all true when field missing", () => {
    const mockUser: User = {
      id: "user-1",
      name: "Test",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: { dates: true, delegation: true },
      email_verified_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    renderWithAuth(mockUser);

    expect(screen.getByTestId("avail-dates")).toHaveTextContent("true");
    expect(screen.getByTestId("avail-delegation")).toHaveTextContent("true");
    expect(screen.getByTestId("eff-dates")).toHaveTextContent("true");
  });

  test("userFlags reflects raw preference regardless of availability", () => {
    const mockUser: User = {
      id: "user-1",
      name: "Test",
      email: "test@example.com",
      phone: null,
      notes: null,
      bio: null,
      avatar_url: null,
      feature_flags: { dates: true, delegation: false },
      available_feature_flags: {
        dates: false,
        delegation: false,
      },
      email_verified_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    };

    renderWithAuth(mockUser);

    expect(screen.getByTestId("user-dates")).toHaveTextContent("true");
    expect(screen.getByTestId("user-delegation")).toHaveTextContent("false");
    expect(screen.getByTestId("eff-dates")).toHaveTextContent("false");
    expect(screen.getByTestId("eff-delegation")).toHaveTextContent("false");
  });
});
