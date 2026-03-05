<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithContext;
use App\Models\Project;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Create Project')]
final class CreateProject extends Tool
{
    use InteractsWithContext;

    #[\Override]
    public function description(): string
    {
        return <<<'DESC'
            Create a new project bucket for organising tasks. Returns the new
            project's UUID, which can immediately be passed as project_id when
            creating or updating tasks. Ownership is automatically set to you.
            DESC;
    }

    #[\Override]
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        // Dynamic: new writable project columns surface automatically.
        return $this->schemas()->applyTo($schema, 'project', required: ['name'], descriptions: [
            'name' => 'Human-friendly project name (required).',
            'color' => 'Hex colour code for UI accent, e.g. "#FF5733".',
        ]);
    }

    #[\Override]
    public function handle(array $arguments): ToolResult
    {
        $user = $this->guard()->user();

        $this->validate($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'color.regex' => 'The colour must be a valid hex code (e.g. #FF5733).',
        ]);

        $clean = $this->guard()->sanitise($arguments, 'project');
        $this->guard()->assertOwnsReferences($clean, 'project');

        $project = Project::create([
            ...$clean,
            'user_id' => $user->id,
        ]);

        return $this->success([
            'id' => (string) $project->id,
            'name' => $project->name,
            'color' => $project->color,
        ], 'Project created.');
    }
}
