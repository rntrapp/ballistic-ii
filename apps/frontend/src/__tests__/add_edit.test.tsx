import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Home from "@/app/page";
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

jest.mock("@/lib/api", () => ({
  fetchProjects: jest.fn().mockResolvedValue([]),
  createProject: jest.fn().mockResolvedValue({
    id: "new-proj",
    name: "New Project",
    user_id: "user-1",
    color: null,
    archived_at: null,
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
    deleted_at: null,
  }),
  fetchItems: jest.fn().mockImplementation((params) => {
    // Return empty array for assigned_to_me and delegated calls
    if (params?.assigned_to_me || params?.delegated) {
      return Promise.resolve([]);
    }
    return Promise.resolve([
      {
        id: "1",
        user_id: "user-1",
        assignee_id: null,
        project_id: null,
        title: "Sample",
        description: null,
        status: "todo",
        position: 0,
        cognitive_load_score: null,
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
      },
    ]);
  }),
  createItem: jest.fn().mockResolvedValue({
    id: "2",
    user_id: "user-1",
    assignee_id: null,
    project_id: null,
    title: "New task",
    description: null,
    status: "todo",
    position: 1,
    cognitive_load_score: null,
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
  }),
  updateItem: jest.fn().mockResolvedValue({
    id: "1",
    user_id: "user-1",
    assignee_id: null,
    project_id: null,
    title: "Sample",
    description: null,
    status: "todo",
    position: 0,
    cognitive_load_score: null,
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
  }),
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

describe("add/edit", () => {
  test("open add form and submit", async () => {
    renderWithAuth(<Home />);

    // Wait for loading to complete
    await waitFor(() => {
      expect(
        screen.getByRole("button", { name: /add a new task/i }),
      ).toBeInTheDocument();
    });

    await userEvent.click(
      screen.getByRole("button", { name: /add a new task/i }),
    );
    const field = screen.getByPlaceholderText(/^Task$/i);
    await userEvent.type(field, "New task");
    await userEvent.click(screen.getByRole("button", { name: /^add$/i }));
    expect(await screen.findByText(/^New task$/i)).toBeInTheDocument();
  });
});
