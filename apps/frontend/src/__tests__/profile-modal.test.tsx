import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ProfileModal } from "@/components/ProfileModal";
import { updateUser } from "@/lib/api";

const logoutMock = jest.fn().mockResolvedValue(undefined);
const refreshUserMock = jest.fn().mockResolvedValue(undefined);

const mockUser = {
  id: "u-1",
  name: "Jane Doe",
  email: "jane@example.com",
  phone: "0412345678",
  notes: null,
  bio: null,
  avatar_url: null,
  feature_flags: { dates: false, delegation: false },
  email_verified_at: "2026-01-01T00:00:00Z",
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

jest.mock("@/contexts/AuthContext", () => ({
  useAuth: () => ({
    user: mockUser,
    logout: logoutMock,
    refreshUser: refreshUserMock,
  }),
}));

jest.mock("@/lib/api", () => ({
  updateUser: jest.fn(),
}));

describe("ProfileModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (updateUser as jest.Mock).mockResolvedValue({});
  });

  test("does not render when isOpen is false", () => {
    render(<ProfileModal isOpen={false} onClose={jest.fn()} />);
    expect(screen.queryByText("Profile")).not.toBeInTheDocument();
  });

  test("renders user details when open", () => {
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    expect(screen.getByText("Profile")).toBeInTheDocument();
    expect(screen.getByDisplayValue("Jane Doe")).toBeInTheDocument();
    expect(screen.getByDisplayValue("jane@example.com")).toBeInTheDocument();
    expect(screen.getByDisplayValue("0412345678")).toBeInTheDocument();
    expect(screen.getByText("JD")).toBeInTheDocument();
  });

  test("calls onClose when close button is clicked", async () => {
    const onClose = jest.fn();
    const user = userEvent.setup();

    render(<ProfileModal isOpen={true} onClose={onClose} />);

    await user.click(screen.getByRole("button", { name: "Close" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  test("calls onClose when Escape is pressed", () => {
    const onClose = jest.fn();
    render(<ProfileModal isOpen={true} onClose={onClose} />);

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  test("submits profile update with correct payload", async () => {
    const user = userEvent.setup();
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledTimes(1);
    });

    const call = (updateUser as jest.Mock).mock.calls[0][0];
    expect(call.name).toBe("Jane Doe");
    expect(call.email).toBe("jane@example.com");
    expect(call.phone).toBe("0412345678");
    expect(call.bio).toBeNull();
    expect(call.avatar_url).toBeNull();

    expect(refreshUserMock).toHaveBeenCalledTimes(1);
  });

  test("submits modified name after editing", async () => {
    const user = userEvent.setup();
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    const nameInput = screen.getByLabelText("Name");
    await user.clear(nameInput);
    await user.type(nameInput, "Jane Smith");

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledTimes(1);
    });

    const call = (updateUser as jest.Mock).mock.calls[0][0];
    expect(call.name).toBe("Jane Smith");
  });

  test("sends null for empty phone field", async () => {
    const user = userEvent.setup();
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    const phoneInput = screen.getByLabelText("Phone");
    await user.clear(phoneInput);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledTimes(1);
    });

    const call = (updateUser as jest.Mock).mock.calls[0][0];
    expect(call.phone).toBeNull();
  });

  test("shows generic fallback message when error has no message", async () => {
    // Error() with no message → falls back to the generic string
    (updateUser as jest.Mock).mockRejectedValueOnce(new Error());
    const user = userEvent.setup();

    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(
        screen.getByText("Failed to save profile. Please try again."),
      ).toBeInTheDocument();
    });
  });

  test("calls logout when log out button is clicked", async () => {
    const user = userEvent.setup();
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    await user.click(screen.getByRole("button", { name: "Log out" }));

    await waitFor(() => {
      expect(logoutMock).toHaveBeenCalledTimes(1);
    });
  });

  test("shows success feedback after save", async () => {
    const user = userEvent.setup();
    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(screen.getByText("Profile updated.")).toBeInTheDocument();
    });
  });

  test("calls onClose when backdrop is clicked", async () => {
    const onClose = jest.fn();
    const user = userEvent.setup();

    render(<ProfileModal isOpen={true} onClose={onClose} />);

    // Click the outer backdrop (the dialog element itself)
    await user.click(screen.getByRole("dialog"));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  test("does not call onClose while save is in progress", async () => {
    let resolveUpdate!: () => void;
    (updateUser as jest.Mock).mockImplementation(
      () =>
        new Promise<Record<string, never>>((res) => {
          resolveUpdate = () => res({});
        }),
    );

    const onClose = jest.fn();
    const user = userEvent.setup();

    render(<ProfileModal isOpen={true} onClose={onClose} />);

    // Start a save that won't resolve yet
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    // Try Escape and close button while saving — both should be no-ops
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    await user.click(screen.getByRole("button", { name: "Close" }));

    expect(onClose).not.toHaveBeenCalled();

    // Resolve the save to clean up
    resolveUpdate();
    await waitFor(() => {
      expect(screen.getByText("Profile updated.")).toBeInTheDocument();
    });
  });

  test("surfaces server error message from API", async () => {
    (updateUser as jest.Mock).mockRejectedValueOnce(
      new Error("The email has already been taken."),
    );
    const user = userEvent.setup();

    render(<ProfileModal isOpen={true} onClose={jest.fn()} />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(
        screen.getByText("The email has already been taken."),
      ).toBeInTheDocument();
    });
  });
});
