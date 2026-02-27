import { fetchItems, createItem } from "../lib/api";

// Mock fetch globally
global.fetch = jest.fn();

// Mock auth
jest.mock("../lib/auth", () => ({
  getToken: jest.fn(() => "test-token"),
  clearToken: jest.fn(),
  getAuthHeaders: jest.fn(() => ({
    "Content-Type": "application/json",
    Accept: "application/json",
    Authorization: "Bearer test-token",
  })),
}));

describe("API Functionality", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (global.fetch as jest.Mock).mockClear();
  });

  test("should call API endpoint for fetching items", async () => {
    // Mock successful response
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => [],
    });

    await fetchItems();

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/api/items"),
      expect.objectContaining({
        method: "GET",
        cache: "no-store",
      }),
    );
  });

  test("should include auth headers in requests", async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => [],
    });

    await fetchItems();

    const callArgs = (global.fetch as jest.Mock).mock.calls[0];
    expect(callArgs[1].headers).toEqual(
      expect.objectContaining({
        Authorization: "Bearer test-token",
      }),
    );
  });

  test("should handle POST requests correctly", async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 201,
      json: async () => ({
        id: "1",
        user_id: "user-1",
        project_id: null,
        title: "Test Task",
        description: null,
        status: "todo",
        position: 0,
        cognitive_load_score: null,
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
        deleted_at: null,
      }),
    });

    await createItem({
      title: "Test Task",
      status: "todo",
      description: "Test description",
    });

    const callArgs = (global.fetch as jest.Mock).mock.calls[0];
    expect(callArgs[1].method).toBe("POST");
    expect(callArgs[1].headers["Content-Type"]).toBe("application/json");
  });

  test("should return items from server response (server-side filtering)", async () => {
    // Server now filters out completed/cancelled items by default
    // The frontend just returns what the server sends
    const mockResponse = [
      {
        id: "1",
        user_id: "user-1",
        project_id: null,
        title: "Active Task 1",
        description: null,
        status: "todo",
        position: 0,
        cognitive_load_score: null,
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
        deleted_at: null,
      },
      {
        id: "4",
        user_id: "user-1",
        project_id: null,
        title: "Active Task 2",
        description: null,
        status: "doing",
        position: 1,
        cognitive_load_score: null,
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
        deleted_at: null,
      },
    ];

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => mockResponse,
    });

    const result = await fetchItems();

    // Should return exactly what the server sends
    expect(result).toHaveLength(2);
    expect(result[0].title).toBe("Active Task 1");
    expect(result[0].status).toBe("todo");
    expect(result[1].title).toBe("Active Task 2");
    expect(result[1].status).toBe("doing");
  });

  test("should handle paginated API shape with data property", async () => {
    const mockResponse = {
      data: [
        {
          id: "1",
          user_id: "user-1",
          project_id: null,
          title: "Active Task 1",
          description: null,
          status: "todo",
          position: 0,
          cognitive_load_score: null,
          created_at: "2025-01-01T00:00:00Z",
          updated_at: "2025-01-01T00:00:00Z",
          deleted_at: null,
        },
      ],
      meta: { current_page: 1, per_page: 15, total: 1 },
    };

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => mockResponse,
    });

    const result = await fetchItems();
    expect(result).toHaveLength(1);
    expect(result[0].title).toBe("Active Task 1");
  });
});
