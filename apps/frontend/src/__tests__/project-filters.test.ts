import {
  filterItemsByExcludedProjects,
  NO_PROJECT_FILTER_ID,
} from "@/lib/projectFilters";
import type { Item } from "@/types";

const makeItem = (overrides: Partial<Item>): Item => ({
  id: "item-1",
  user_id: "user-1",
  assignee_id: null,
  project_id: null,
  title: "Task",
  description: null,
  assignee_notes: null,
  status: "todo",
  position: 0,
  scheduled_date: null,
  due_date: null,
  completed_at: null,
  recurrence_rule: null,
  recurrence_parent_id: null,
  recurrence_strategy: null,
  is_recurring_template: false,
  is_recurring_instance: false,
  is_assigned: false,
  is_delegated: false,
  created_at: "2026-03-01T00:00:00Z",
  updated_at: "2026-03-01T00:00:00Z",
  deleted_at: null,
  ...overrides,
});

describe("projectFilters", () => {
  test("can exclude items with no project", () => {
    const items = [
      makeItem({ id: "inbox", project_id: null }),
      makeItem({ id: "project", project_id: "project-1" }),
    ];

    const filtered = filterItemsByExcludedProjects(
      items,
      new Set([NO_PROJECT_FILTER_ID]),
    );

    expect(filtered).toEqual([items[1]]);
  });

  test("can exclude a named project without hiding inbox items", () => {
    const items = [
      makeItem({ id: "inbox", project_id: null }),
      makeItem({ id: "project-a", project_id: "project-a" }),
      makeItem({ id: "project-b", project_id: "project-b" }),
    ];

    const filtered = filterItemsByExcludedProjects(
      items,
      new Set(["project-a"]),
    );

    expect(filtered).toEqual([items[0], items[2]]);
  });
});
