import type { Item } from "@/types";

export const NO_PROJECT_FILTER_ID = "__no_project__";

export function filterItemsByExcludedProjects(
  items: Item[],
  excludedProjectIds: Set<string>,
): Item[] {
  if (excludedProjectIds.size === 0) {
    return items;
  }

  const excludeNoProject = excludedProjectIds.has(NO_PROJECT_FILTER_ID);

  return items.filter((item) => {
    if (item.project_id === null) {
      return !excludeNoProject;
    }

    return !excludedProjectIds.has(item.project_id);
  });
}
