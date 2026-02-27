import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ItemForm } from "@/components/ItemForm";
import { ProjectCombobox } from "@/components/ProjectCombobox";
import type { Project } from "@/types";

describe("ProjectCombobox", () => {
  const mockProjects: Project[] = [
    {
      id: "proj-1",
      user_id: "user-1",
      name: "Work",
      color: "#3B82F6",
      archived_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
      deleted_at: null,
    },
    {
      id: "proj-2",
      user_id: "user-1",
      name: "Personal",
      color: "#10B981",
      archived_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
      deleted_at: null,
    },
  ];

  test("renders with 'No project' when value is null", () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    expect(screen.getByText("No project")).toBeInTheDocument();
  });

  test("renders selected project name when value is set", () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value="proj-1"
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    expect(screen.getByText("Work")).toBeInTheDocument();
  });

  test("opens dropdown on click and shows all projects", async () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    expect(
      screen.getByPlaceholderText("Search or create..."),
    ).toBeInTheDocument();
    expect(screen.getByText("Work")).toBeInTheDocument();
    expect(screen.getByText("Personal")).toBeInTheDocument();
  });

  test("filters projects as user types", async () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    const searchInput = screen.getByPlaceholderText("Search or create...");
    await userEvent.type(searchInput, "Wor");

    // Work should be visible, Personal should not
    expect(screen.getByText("Work")).toBeInTheDocument();
    expect(screen.queryByText("Personal")).not.toBeInTheDocument();
  });

  test("shows 'Create' option when search doesn't match any project", async () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    const searchInput = screen.getByPlaceholderText("Search or create...");
    await userEvent.type(searchInput, "Errands");

    expect(screen.getByText('Create "Errands"')).toBeInTheDocument();
  });

  test("calls onChange when selecting an existing project", async () => {
    const onChange = jest.fn();
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={onChange}
        onCreateProject={jest.fn()}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    const workOption = screen.getByText("Work");
    await userEvent.click(workOption);

    expect(onChange).toHaveBeenCalledWith("proj-1");
  });

  test("calls onChange with null when selecting 'No project'", async () => {
    const onChange = jest.fn();
    render(
      <ProjectCombobox
        projects={mockProjects}
        value="proj-1"
        onChange={onChange}
        onCreateProject={jest.fn()}
      />,
    );

    // Trigger shows "Work" since value is proj-1
    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    // Find the "No project" option in the dropdown
    const noProjectOption = screen.getByText("No project");
    await userEvent.click(noProjectOption);

    expect(onChange).toHaveBeenCalledWith(null);
  });

  test("creates new project and auto-selects it", async () => {
    const onChange = jest.fn();
    const onCreateProject = jest.fn().mockResolvedValue({
      id: "new-proj",
      user_id: "user-1",
      name: "Errands",
      color: null,
      archived_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
      deleted_at: null,
    });

    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={onChange}
        onCreateProject={onCreateProject}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    const searchInput = screen.getByPlaceholderText("Search or create...");
    await userEvent.type(searchInput, "Errands");

    const createOption = screen.getByText('Create "Errands"');
    await userEvent.click(createOption);

    await waitFor(() => {
      expect(onCreateProject).toHaveBeenCalledWith("Errands");
      expect(onChange).toHaveBeenCalledWith("new-proj");
    });
  });

  test("closes dropdown on Escape key", async () => {
    render(
      <ProjectCombobox
        projects={mockProjects}
        value={null}
        onChange={jest.fn()}
        onCreateProject={jest.fn()}
      />,
    );

    const trigger = screen.getByRole("button");
    await userEvent.click(trigger);

    expect(
      screen.getByPlaceholderText("Search or create..."),
    ).toBeInTheDocument();

    await userEvent.keyboard("{Escape}");

    expect(
      screen.queryByPlaceholderText("Search or create..."),
    ).not.toBeInTheDocument();
  });
});

describe("ItemForm with ProjectCombobox", () => {
  const mockProjects: Project[] = [
    {
      id: "proj-1",
      user_id: "user-1",
      name: "Work",
      color: "#3B82F6",
      archived_at: null,
      created_at: "2025-01-01T00:00:00Z",
      updated_at: "2025-01-01T00:00:00Z",
      deleted_at: null,
    },
  ];

  test("includes project_id in onSubmit when project is selected", async () => {
    const onSubmit = jest.fn();
    const onCancel = jest.fn();
    const onCreateProject = jest.fn();

    render(
      <ItemForm
        onSubmit={onSubmit}
        onCancel={onCancel}
        projects={mockProjects}
        onCreateProject={onCreateProject}
      />,
    );

    // Fill in the title
    const titleInput = screen.getByPlaceholderText("Task");
    await userEvent.type(titleInput, "My new task");

    // Open More settings
    const moreSettingsButton = screen.getByText("More settings");
    await userEvent.click(moreSettingsButton);

    // Open project combobox and select a project
    const projectTrigger = screen.getByText("No project");
    await userEvent.click(projectTrigger);

    const workOption = screen.getByText("Work");
    await userEvent.click(workOption);

    // Submit the form
    const submitButton = screen.getByRole("button", { name: /save/i });
    await userEvent.click(submitButton);

    expect(onSubmit).toHaveBeenCalledWith({
      title: "My new task",
      description: undefined,
      project_id: "proj-1",
      cognitive_load: null,
      scheduled_date: null,
      due_date: null,
      recurrence_rule: null,
      recurrence_strategy: null,
      assignee_id: null,
    });
  });

  test("passes null project_id when no project is selected", async () => {
    const onSubmit = jest.fn();
    const onCancel = jest.fn();

    render(
      <ItemForm
        onSubmit={onSubmit}
        onCancel={onCancel}
        projects={mockProjects}
        onCreateProject={jest.fn()}
      />,
    );

    // Fill in the title
    const titleInput = screen.getByPlaceholderText("Task");
    await userEvent.type(titleInput, "Task without project");

    // Submit without selecting a project
    const submitButton = screen.getByRole("button", { name: /save/i });
    await userEvent.click(submitButton);

    expect(onSubmit).toHaveBeenCalledWith({
      title: "Task without project",
      description: undefined,
      project_id: null,
      cognitive_load: null,
      scheduled_date: null,
      due_date: null,
      recurrence_rule: null,
      recurrence_strategy: null,
      assignee_id: null,
    });
  });
});
