import { render, screen, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ItemRow } from "@/components/ItemRow";
import type { Item, Status } from "@/types";

jest.mock("@/lib/api", () => ({
  updateStatus: jest
    .fn()
    .mockImplementation(async (_id: string, _status: Status) => ({
      id: _id,
      user_id: "user-1",
      project_id: null,
      title: "Sample",
      description: null,
      status: _status,
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
      deleted_at: null,
    })),
}));

describe("ItemRow", () => {
  const base: Item = {
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
  };

  test("clicking status circle advances status", async () => {
    const onChange = jest.fn();
    const onOptimisticReorder = jest.fn();
    const onEdit = jest.fn();
    const onDragStart = jest.fn();
    const onDragEnter = jest.fn();
    const onDropItem = jest.fn();
    const onDragEnd = jest.fn();

    render(
      <ItemRow
        item={base}
        onChange={onChange}
        onOptimisticReorder={onOptimisticReorder}
        index={0}
        onEdit={onEdit}
        isFirst={false}
        onDragStart={onDragStart}
        onDragEnter={onDragEnter}
        onDropItem={onDropItem}
        onDragEnd={onDragEnd}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const button = screen.getByRole("button", { name: /toggle status/i });
    await userEvent.click(button);
    expect(onChange).toHaveBeenCalled();
  });

  test("drag interactions trigger callbacks", () => {
    const onChange = jest.fn();
    const onOptimisticReorder = jest.fn();
    const onEdit = jest.fn();
    const onDragStart = jest.fn();
    const onDragEnter = jest.fn();
    const onDropItem = jest.fn();
    const onDragEnd = jest.fn();

    render(
      <ItemRow
        item={base}
        onChange={onChange}
        onOptimisticReorder={onOptimisticReorder}
        index={0}
        onEdit={onEdit}
        isFirst={false}
        onDragStart={onDragStart}
        onDragEnter={onDragEnter}
        onDropItem={onDropItem}
        onDragEnd={onDragEnd}
        draggingId={null}
        dragOverId={null}
        onError={jest.fn()}
      />,
    );

    const row = document.querySelector('[data-item-id="1"]');
    expect(row).not.toBeNull();

    if (row) {
      fireEvent.dragStart(row);
      expect(onDragStart).toHaveBeenCalledWith("1");

      fireEvent.dragEnter(row);
      expect(onDragEnter).toHaveBeenCalledWith("1");

      fireEvent.drop(row);
      expect(onDropItem).toHaveBeenCalledWith("1");
    }
  });
});
