import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom";
import Home from "../app/page";
import * as api from "../lib/api";
import { AuthProvider } from "@/contexts/AuthContext";

// Mock auth
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
  AuthError: class AuthError extends Error {
    errors: Record<string, string[]>;
    constructor(message: string, errors: Record<string, string[]> = {}) {
      super(message);
      this.name = "AuthError";
      this.errors = errors;
    }
  },
}));

// Mock the API
jest.mock("../lib/api", () => ({
  fetchItems: jest.fn(() => Promise.resolve([])),
  fetchProjects: jest.fn(() => Promise.resolve([])),
  createProject: jest.fn(() =>
    Promise.resolve({
      id: "new-proj",
      name: "New Project",
      user_id: "user-1",
      color: null,
      archived_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
      deleted_at: null,
    }),
  ),
  createItem: jest.fn(),
  fetchUser: jest.fn().mockResolvedValue({
    id: "user-1",
    name: "Test User",
    email: "test@example.com",
    phone: null,
    notes: null,
    feature_flags: { dates: false, delegation: false },
    email_verified_at: "2025-01-01T00:00:00Z",
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
  }),
  updateUser: jest.fn().mockImplementation((data) =>
    Promise.resolve({
      id: "user-1",
      name: "Test User",
      email: "test@example.com",
      phone: null,
      notes: null,
      feature_flags: data.feature_flags || { dates: false, delegation: false },
      email_verified_at: "2025-01-01T00:00:00Z",
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
    }),
  ),
}));

// Mock next/navigation
jest.mock("next/navigation", () => ({
  useRouter: () => ({
    push: jest.fn(),
  }),
}));

const renderWithAuth = (component: React.ReactElement) => {
  return render(<AuthProvider>{component}</AuthProvider>);
};

describe("Item Creation Response Handling", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test("newly created item text should persist after server response", async () => {
    const { createItem } = api;

    // Mock createItem to return a properly formatted item after a delay
    (createItem as jest.Mock).mockImplementation(
      () =>
        new Promise((resolve) =>
          setTimeout(
            () =>
              resolve({
                id: "server-123",
                user_id: "user-1",
                project_id: null,
                title: "Test Task",
                description: "Test description",
                status: "todo",
                position: 0,
                created_at: "2025-10-24T00:00:00Z",
                updated_at: "2025-10-24T00:00:00Z",
                deleted_at: null,
              }),
            100,
          ),
        ),
    );

    renderWithAuth(<Home />);

    // Wait for initial load and ensure the add button is visible
    await waitFor(() => {
      expect(screen.queryByText("Loading")).not.toBeInTheDocument();
    });

    // Click the add button
    const addButton = await screen.findByText("Add new task...");
    fireEvent.click(addButton);

    // Fill out the form
    const titleInput = screen.getByPlaceholderText("Task");
    fireEvent.change(titleInput, { target: { value: "Test Task" } });

    // Expand more settings to access description field
    const moreSettingsButton = screen.getByText("More settings");
    fireEvent.click(moreSettingsButton);

    const descriptionInput = screen.getByPlaceholderText("Add more details...");
    fireEvent.change(descriptionInput, {
      target: { value: "Test description" },
    });

    // Submit the form
    const saveButton = screen.getByText("Add");
    fireEvent.click(saveButton);

    // Verify the item appears immediately (optimistic update)
    expect(screen.getByText("Test Task")).toBeInTheDocument();

    // Wait for the server response to come back
    await waitFor(
      () => {
        expect(createItem).toHaveBeenCalledWith({
          title: "Test Task",
          description: "Test description",
          status: "todo",
          project_id: null,
          position: 0,
          cognitive_load: null,
          scheduled_date: null,
          due_date: null,
          recurrence_rule: null,
          recurrence_strategy: null,
          assignee_id: null,
        });
      },
      { timeout: 200 },
    );

    // Verify the text is still visible after server response
    await waitFor(() => {
      expect(screen.getByText("Test Task")).toBeInTheDocument();
    });
  });

  test("optimistic item stays visible when API wraps response in data", async () => {
    const { createItem } = api;

    (createItem as jest.Mock).mockImplementation(() =>
      Promise.resolve({
        data: {
          id: "server-456",
          user_id: "user-1",
          project_id: null,
          title: "Wrapped Task",
          description: "Wrapped description",
          status: "todo",
          position: 0,
          created_at: "2025-10-24T00:00:00Z",
          updated_at: "2025-10-24T00:00:00Z",
          deleted_at: null,
        },
      }),
    );

    renderWithAuth(<Home />);

    await waitFor(() => {
      expect(screen.queryByText("Loading")).not.toBeInTheDocument();
    });

    fireEvent.click(await screen.findByText("Add new task..."));

    fireEvent.change(screen.getByPlaceholderText("Task"), {
      target: { value: "Wrapped Task" },
    });
    fireEvent.click(screen.getByText("More settings"));
    fireEvent.change(screen.getByPlaceholderText("Add more details..."), {
      target: { value: "Wrapped description" },
    });
    fireEvent.click(screen.getByText("Add"));

    expect(screen.getByText("Wrapped Task")).toBeInTheDocument();

    await waitFor(() => {
      expect(createItem).toHaveBeenCalled();
    });

    await waitFor(() => {
      expect(screen.getByText("Wrapped Task")).toBeInTheDocument();
    });
  });
});
