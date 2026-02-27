import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { ItemRow } from "@/components/ItemRow";
import type { Item } from "@/types";

// Mock the API functions
jest.mock("@/lib/api", () => ({
  updateStatus: jest.fn(),
}));

describe("Move to Top functionality", () => {
  const mockItem: Item = {
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
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
    deleted_at: null,
  };

  const mockOnChange = jest.fn();
  const mockOnOptimisticReorder = jest.fn();
  const mockOnEdit = jest.fn();

  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("should render the move to top button when item is not first", () => {
    render(
      <ItemRow
        item={mockItem}
        onChange={mockOnChange}
        onOptimisticReorder={mockOnOptimisticReorder}
        index={1}
        onEdit={mockOnEdit}
        isFirst={false}
        onDragStart={jest.fn()}
        onDragEnter={jest.fn()}
        onDropItem={jest.fn()}
        onDragEnd={jest.fn()}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const moveToTopButton = screen.getByLabelText("Move to top");
    expect(moveToTopButton).toBeInTheDocument();
    expect(moveToTopButton).toHaveTextContent("⇈");
  });

  it("should not render the move to top button when item is first", () => {
    render(
      <ItemRow
        item={mockItem}
        onChange={mockOnChange}
        onOptimisticReorder={mockOnOptimisticReorder}
        index={0}
        onEdit={mockOnEdit}
        isFirst={true}
        onDragStart={jest.fn()}
        onDragEnter={jest.fn()}
        onDropItem={jest.fn()}
        onDragEnd={jest.fn()}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const moveToTopButton = screen.queryByLabelText("Move to top");
    expect(moveToTopButton).not.toBeInTheDocument();
  });

  it('should call onOptimisticReorder with "top" direction when clicked', async () => {
    render(
      <ItemRow
        item={mockItem}
        onChange={mockOnChange}
        onOptimisticReorder={mockOnOptimisticReorder}
        index={2}
        onEdit={mockOnEdit}
        isFirst={false}
        onDragStart={jest.fn()}
        onDragEnter={jest.fn()}
        onDropItem={jest.fn()}
        onDragEnd={jest.fn()}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const moveToTopButton = screen.getByLabelText("Move to top");
    fireEvent.click(moveToTopButton);

    await waitFor(() => {
      expect(mockOnOptimisticReorder).toHaveBeenCalledWith("2", "top");
    });
  });

  it("does not render move-to-top for the first item", () => {
    render(
      <ItemRow
        item={mockItem}
        onChange={mockOnChange}
        onOptimisticReorder={mockOnOptimisticReorder}
        index={0}
        onEdit={mockOnEdit}
        isFirst={true}
        onDragStart={jest.fn()}
        onDragEnter={jest.fn()}
        onDropItem={jest.fn()}
        onDragEnd={jest.fn()}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const moveToTopButton = screen.queryByLabelText("Move to top");
    expect(moveToTopButton).toBeNull();
  });
});

describe("Move to Top optimistic update", () => {
  it("should move item to the top of the list", () => {
    const baseItem = {
      assignee_id: null,
      project_id: null,
      description: null,
      scheduled_date: null,
      due_date: null,
      completed_at: null,
      recurrence_rule: null,
      recurrence_parent_id: null,
      is_recurring_template: false,
      is_recurring_instance: false,
      assignee_notes: null,
      is_assigned: false,
      is_delegated: false,
      deleted_at: null,
    };

    const items: Item[] = [
      {
        ...baseItem,
        id: "1",
        user_id: "user-1",
        title: "First Task",
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
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
      },
      {
        ...baseItem,
        id: "2",
        user_id: "user-1",
        title: "Second Task",
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
        created_at: "2025-01-02T00:00:00Z",
        updated_at: "2025-01-02T00:00:00Z",
      },
      {
        ...baseItem,
        id: "3",
        user_id: "user-1",
        title: "Third Task",
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
        created_at: "2025-01-03T00:00:00Z",
        updated_at: "2025-01-03T00:00:00Z",
      },
    ];

    // Simulate the optimistic reorder logic
    const itemId = "3";
    const direction = "top";
    const currentIndex = items.findIndex((item) => item.id === itemId);
    const newList = [...items];

    if (direction === "top" && currentIndex > 0) {
      const [item] = newList.splice(currentIndex, 1);
      newList.unshift(item);
    }

    expect(newList[0].id).toBe("3");
    expect(newList[1].id).toBe("1");
    expect(newList[2].id).toBe("2");
    expect(newList).toHaveLength(3);
  });

  it("should not modify the list when item is already at the top", () => {
    const baseItem = {
      assignee_id: null,
      project_id: null,
      description: null,
      scheduled_date: null,
      due_date: null,
      completed_at: null,
      recurrence_rule: null,
      recurrence_parent_id: null,
      is_recurring_template: false,
      is_recurring_instance: false,
      assignee_notes: null,
      is_assigned: false,
      is_delegated: false,
      deleted_at: null,
    };

    const items: Item[] = [
      {
        ...baseItem,
        id: "1",
        user_id: "user-1",
        title: "First Task",
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
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
      },
      {
        ...baseItem,
        id: "2",
        user_id: "user-1",
        title: "Second Task",
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
        created_at: "2025-01-02T00:00:00Z",
        updated_at: "2025-01-02T00:00:00Z",
      },
    ];

    // Simulate the optimistic reorder logic for the first item
    const itemId = "1";
    const direction = "top";
    const currentIndex = items.findIndex((item) => item.id === itemId);
    const newList = [...items];

    if (direction === "top" && currentIndex > 0) {
      const [item] = newList.splice(currentIndex, 1);
      newList.unshift(item);
    }

    // List should remain unchanged
    expect(newList[0].id).toBe("1");
    expect(newList[1].id).toBe("2");
    expect(newList).toHaveLength(2);
  });
});
