<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Connection;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

final class LocalTestingSeeder extends Seeder
{
    public function run(): void
    {
        $owner = $this->upsertUser(
            'local@example.com',
            [
                'name' => 'Local Tester',
                'password' => 'password',
                'email_verified_at' => now(),
                'notes' => 'Local demo account for manual testing.',
                'bio' => 'Seeded by LocalTestingSeeder.',
                'is_admin' => true,
                'feature_flags' => [],
            ],
        );

        $assignee = $this->upsertUser(
            'alex.collaborator@example.com',
            [
                'name' => 'Alex Collaborator',
                'password' => 'password',
                'email_verified_at' => now(),
                'notes' => 'Connected helper account for delegated item testing.',
                'bio' => 'Secondary seeded user.',
                'is_admin' => false,
                'feature_flags' => [],
            ],
        );

        Connection::query()->updateOrCreate(
            [
                'requester_id' => $owner->id,
                'addressee_id' => $assignee->id,
            ],
            ['status' => 'accepted'],
        );

        $launchPrep = $this->upsertProject($owner, 'Launch Prep', '#0f766e');
        $personalOps = $this->upsertProject($owner, 'Personal Ops', '#b45309');

        $this->upsertItem($owner, [
            'project_id' => null,
            'assignee_id' => null,
            'title' => 'Inbox capture: chase supplier quote',
            'description' => 'Inbox item with no project, ready to triage.',
            'status' => 'todo',
            'position' => 10,
            'scheduled_date' => null,
            'due_date' => null,
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $launchPrep->id,
            'assignee_id' => null,
            'title' => 'Polish onboarding flow copy',
            'description' => 'In-progress project work item.',
            'status' => 'doing',
            'position' => 20,
            'scheduled_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $personalOps->id,
            'assignee_id' => null,
            'title' => 'Invoice March hosting costs',
            'description' => 'Completed item for activity-log and filtering checks.',
            'status' => 'done',
            'position' => 30,
            'scheduled_date' => now()->subDays(4)->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
            'completed_at' => now()->subDay(),
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $launchPrep->id,
            'assignee_id' => null,
            'title' => 'Cancel outdated venue shortlist',
            'description' => 'Explicitly cancelled item to exercise the wontdo state.',
            'status' => 'wontdo',
            'position' => 40,
            'scheduled_date' => now()->subDays(7)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'completed_at' => now()->subDays(3),
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $launchPrep->id,
            'assignee_id' => null,
            'title' => 'Follow up overdue sponsor approval',
            'description' => 'Overdue todo item for dashboard and list filters.',
            'status' => 'todo',
            'position' => 50,
            'scheduled_date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $personalOps->id,
            'assignee_id' => null,
            'title' => 'Prep next sprint planning agenda',
            'description' => 'Future-scheduled item that should stay out of active views until due.',
            'status' => 'todo',
            'position' => 60,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => null,
            'assignee_id' => null,
            'title' => 'Recurring: weekly ops review',
            'description' => 'Recurring template item using carry-over behaviour.',
            'status' => 'todo',
            'position' => 70,
            'scheduled_date' => null,
            'due_date' => null,
            'completed_at' => null,
            'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
            'recurrence_strategy' => 'carry_over',
            'recurrence_parent_id' => null,
            'assignee_notes' => null,
        ]);

        $this->upsertItem($owner, [
            'project_id' => $launchPrep->id,
            'assignee_id' => $assignee->id,
            'title' => 'Delegated copy pass for launch email',
            'description' => 'Assigned item for delegation and notification flows.',
            'status' => 'doing',
            'position' => 80,
            'scheduled_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
            'completed_at' => null,
            'recurrence_rule' => null,
            'recurrence_strategy' => null,
            'recurrence_parent_id' => null,
            'assignee_notes' => 'Tighten the opening paragraph and CTA.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertItem(User $owner, array $attributes): void
    {
        $item = Item::query()
            ->withTrashed()
            ->firstOrNew([
                'user_id' => $owner->id,
                'title' => $attributes['title'],
            ]);

        $item->fill([
            ...$attributes,
            'user_id' => $owner->id,
        ]);
        $item->save();

        if ($item->trashed()) {
            $item->restore();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertUser(string $email, array $attributes): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            ...$attributes,
            'email' => $email,
        ]);
        $user->save();

        return $user;
    }

    private function upsertProject(User $owner, string $name, string $color): Project
    {
        $project = Project::query()
            ->withTrashed()
            ->firstOrNew([
                'user_id' => $owner->id,
                'name' => $name,
            ]);

        $project->fill([
            'user_id' => $owner->id,
            'name' => $name,
            'color' => $color,
            'archived_at' => null,
        ]);
        $project->save();

        if ($project->trashed()) {
            $project->restore();
        }

        return $project;
    }
}
