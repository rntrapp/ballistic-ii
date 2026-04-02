import { render, screen, waitFor } from "@testing-library/react";
import {
  ActivityLogModal,
  getActivityTimestamp,
} from "@/components/ActivityLogModal";
import { fetchActivityLog } from "@/lib/api";

jest.mock("@/lib/api", () => ({
  fetchActivityLog: jest.fn(),
}));

describe("ActivityLogModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test("shows assignment details, actor details, and activity timestamp", async () => {
    (fetchActivityLog as jest.Mock).mockResolvedValue({
      data: [
        {
          id: "item-1",
          title: "Delegated task",
          status: "done",
          is_assigned: true,
          is_assigned_to_me: false,
          is_delegated: true,
          project: null,
          assignee: {
            id: "user-2",
            name: "Sam Delegate",
            email_masked: "s***@example.com",
          },
          owner: {
            id: "user-1",
            name: "Owner User",
            email_masked: "o***@example.com",
          },
          completed_by: {
            id: "user-2",
            name: "Sam Delegate",
          },
          activity_at: "2026-03-09T11:00:00Z",
          completed_at: "2026-03-09T11:00:00Z",
          created_at: "2026-03-01T09:00:00Z",
          updated_at: "2026-03-10T10:00:00Z",
        },
        {
          id: "item-2",
          title: "Assigned task",
          status: "wontdo",
          is_assigned: true,
          is_assigned_to_me: true,
          is_delegated: false,
          project: null,
          assignee: {
            id: "user-1",
            name: "Owner User",
            email_masked: "o***@example.com",
          },
          owner: {
            id: "user-3",
            name: "Mia Manager",
            email_masked: "m***@example.com",
          },
          completed_by: {
            id: "user-3",
            name: "Mia Manager",
          },
          activity_at: "2026-03-08T08:30:00Z",
          completed_at: null,
          created_at: "2026-03-01T09:00:00Z",
          updated_at: "2026-03-10T10:00:00Z",
        },
      ],
      meta: {
        next_cursor: null,
        prev_cursor: null,
        per_page: 20,
        path: "/api/activity-log",
      },
    });

    render(<ActivityLogModal isOpen={true} onClose={jest.fn()} />);

    await waitFor(() => {
      expect(fetchActivityLog).toHaveBeenCalledTimes(1);
    });

    expect(screen.getByText("Assigned to Sam Delegate")).toBeInTheDocument();
    expect(screen.getByText("Assigned by Mia Manager")).toBeInTheDocument();
    expect(screen.getByText("Marked done by Sam Delegate")).toBeInTheDocument();
    expect(
      screen.getByText("Marked won’t do by Mia Manager"),
    ).toBeInTheDocument();
    expect(screen.getByText(/9 Mar 2026|09 Mar 2026/)).toBeInTheDocument();
    expect(screen.getByText(/8 Mar 2026|08 Mar 2026/)).toBeInTheDocument();
  });

  test("uses updated_at for wontdo items", () => {
    expect(
      getActivityTimestamp({
        id: "item-2",
        title: "Assigned task",
        status: "wontdo",
        is_assigned: true,
        is_assigned_to_me: true,
        is_delegated: false,
        project: null,
        assignee: null,
        owner: null,
        completed_by: null,
        activity_at: "2026-03-08T08:30:00Z",
        completed_at: null,
        created_at: "2026-03-01T09:00:00Z",
        updated_at: "2026-03-10T10:00:00Z",
      }),
    ).toBe("2026-03-08T08:30:00Z");
  });
});
