import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import Home from "@/app/page";
import { AuthProvider } from "@/contexts/AuthContext";
import { reorderItems } from "@/lib/api";

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
  AuthError: class AuthError extends Error {},
}));

// Mock the API functions
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
        title: "First Task",
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
      {
        id: "2",
        user_id: "user-1",
        assignee_id: null,
        project_id: null,
        title: "Second Task",
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
        created_at: "2025-01-02T00:00:00Z",
        updated_at: "2025-01-02T00:00:00Z",
        deleted_at: null,
      },
    ]);
  }),
  createItem: jest.fn().mockResolvedValue({
    id: "1",
    user_id: "user-1",
    assignee_id: null,
    project_id: null,
    title: "First Task",
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
  updateItem: jest.fn(),
  updateStatus: jest.fn(),
  deleteItem: jest.fn(),
  moveItem: jest.fn().mockResolvedValue([
    {
      id: "1",
      user_id: "user-1",
      assignee_id: null,
      project_id: null,
      title: "First Task",
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
    {
      id: "2",
      user_id: "user-1",
      assignee_id: null,
      project_id: null,
      title: "Second Task",
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
      created_at: "2025-01-02T00:00:00Z",
      updated_at: "2025-01-02T00:00:00Z",
      deleted_at: null,
    },
  ]),
  reorderItems: jest.fn().mockResolvedValue(undefined),
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

const renderWithAuth = (component: React.ReactElement) =>
  render(<AuthProvider>{component}</AuthProvider>);

describe("Drag and drop ordering", () => {
  test("dragging an item reorders list and persists", async () => {
    renderWithAuth(<Home />);

    await waitFor(() => {
      expect(screen.getByText("First Task")).toBeInTheDocument();
      expect(screen.getByText("Second Task")).toBeInTheDocument();
    });

    const firstRow = document.querySelector('[data-item-id="1"]');
    const secondRow = document.querySelector('[data-item-id="2"]');

    expect(firstRow).not.toBeNull();
    expect(secondRow).not.toBeNull();

    if (!firstRow || !secondRow) return;

    const dataTransfer = {
      setData: jest.fn(),
      getData: jest.fn(),
      effectAllowed: "",
    } as unknown as DataTransfer;

    fireEvent.dragStart(secondRow, { dataTransfer });
    fireEvent.dragEnter(firstRow, { dataTransfer });
    fireEvent.drop(firstRow, { dataTransfer });
    fireEvent.dragEnd(secondRow, { dataTransfer });

    await waitFor(() => {
      expect(reorderItems as jest.Mock).toHaveBeenCalled();
    });

    await waitFor(() => {
      const orderedIds = Array.from(
        document.querySelectorAll("[data-item-id]"),
      ).map((node) => node.getAttribute("data-item-id"));
      expect(orderedIds[0]).toBe("2");
      expect(orderedIds[1]).toBe("1");
    });
  });
});
