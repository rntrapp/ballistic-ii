import {
  render,
  screen,
  fireEvent,
  waitFor,
  act,
} from "@testing-library/react";
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
        created_at: "2025-10-24T00:00:00Z",
        updated_at: "2025-10-24T00:00:00Z",
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
        created_at: "2025-10-24T00:00:00Z",
        updated_at: "2025-10-24T00:00:00Z",
        deleted_at: null,
      },
    ]);
  }),
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

// Mock scrollIntoView
const mockScrollIntoView = jest.fn();
Object.defineProperty(Element.prototype, "scrollIntoView", {
  value: mockScrollIntoView,
  writable: true,
});

const renderWithAuth = (component: React.ReactElement) => {
  return render(<AuthProvider>{component}</AuthProvider>);
};

describe("Item Addition Order and Scrolling", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockScrollIntoView.mockClear();
  });

  test("new items should be added to the bottom of the list", async () => {
    const { createItem } = api;

    // Mock createItem to return a properly formatted item
    (createItem as jest.Mock).mockImplementation(() =>
      Promise.resolve({
        id: "new-123",
        user_id: "user-1",
        assignee_id: null,
        project_id: null,
        title: "New Task",
        description: "New description",
        status: "todo",
        position: 2,
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
        created_at: "2025-10-24T00:00:00Z",
        updated_at: "2025-10-24T00:00:00Z",
        deleted_at: null,
      }),
    );

    renderWithAuth(<Home />);

    // Wait for items to load (splash screen disappears and items appear)
    await waitFor(() => {
      expect(screen.getByText("First Task")).toBeInTheDocument();
      expect(screen.getByText("Second Task")).toBeInTheDocument();
    });

    // Click the add button
    const addButton = await screen.findByText("Add new task...");
    await act(async () => {
      fireEvent.click(addButton);
    });

    // Fill out the form
    const titleInput = screen.getByPlaceholderText("Task");
    await act(async () => {
      fireEvent.change(titleInput, { target: { value: "New Task" } });
    });

    // Expand more settings to access description field
    const moreSettingsButton = screen.getByText("More settings");
    await act(async () => {
      fireEvent.click(moreSettingsButton);
    });

    const descriptionInput = screen.getByPlaceholderText("Add more details...");
    await act(async () => {
      fireEvent.change(descriptionInput, {
        target: { value: "New description" },
      });
    });

    // Submit the form
    const saveButton = screen.getByText("Add");
    await act(async () => {
      fireEvent.click(saveButton);
    });

    // Verify the new item appears immediately (optimistic update)
    expect(screen.getByText("New Task")).toBeInTheDocument();

    // Get all task elements and verify order
    const taskElements = screen
      .getAllByText(/Task$/)
      .map((el) => el.textContent);
    expect(taskElements).toEqual(["First Task", "Second Task", "New Task"]);
  });

  test("should attempt to scroll to newly added item", async () => {
    const { createItem } = api;

    // Mock createItem to return a properly formatted item
    (createItem as jest.Mock).mockImplementation(() =>
      Promise.resolve({
        id: "scroll-test-123",
        user_id: "user-1",
        assignee_id: null,
        project_id: null,
        title: "Scroll Test Task",
        description: null,
        status: "todo",
        position: 2,
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
        created_at: "2025-10-24T00:00:00Z",
        updated_at: "2025-10-24T00:00:00Z",
        deleted_at: null,
      }),
    );

    // Mock querySelector to track what it's being called with
    const originalQuerySelector = document.querySelector;
    const mockQuerySelector = jest.fn().mockImplementation((selector) => {
      const element = originalQuerySelector.call(document, selector);
      if (element) {
        return element;
      }
      return null;
    });
    document.querySelector = mockQuerySelector;

    renderWithAuth(<Home />);

    // Wait for items to load (splash screen disappears and items appear)
    await waitFor(() => {
      expect(screen.getByText("First Task")).toBeInTheDocument();
    });

    // Click the add button
    const addButton = await screen.findByText("Add new task...");
    await act(async () => {
      fireEvent.click(addButton);
    });

    // Fill out the form
    const titleInput = screen.getByPlaceholderText("Task");
    await act(async () => {
      fireEvent.change(titleInput, { target: { value: "Scroll Test Task" } });
    });

    // Submit the form
    const saveButton = screen.getByText("Add");
    await act(async () => {
      fireEvent.click(saveButton);
    });

    // Wait for the item to appear in the DOM first
    await waitFor(() => {
      expect(screen.getByText("Scroll Test Task")).toBeInTheDocument();
    });

    // Wait for querySelector to be called with the temporary item ID
    await waitFor(
      () => {
        expect(mockQuerySelector).toHaveBeenCalledWith(
          expect.stringMatching(/\[data-item-id="temp-\d+-[a-z0-9]+"\]/),
        );
      },
      { timeout: 300 },
    );

    // Restore original querySelector
    document.querySelector = originalQuerySelector;
  });
});
