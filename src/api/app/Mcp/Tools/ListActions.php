<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\Project;
use App\Models\ProjectAction;

class ListActions extends Tool
{
    public function name(): string
    {
        return 'list_actions';
    }

    public function description(): string
    {
        return 'A project’s actions (named jobs run on one of its servers: clear caches, reindex, restart workers…), from its repository’s .deployme/actions or made in deploy.me: their steps and where each runs, their inputs, when they run by themselves, and whether an agent may start one (open_to_agents) with run_action.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'Only this project’s actions (slug or name). Empty: every project’s.'],
        ];
    }

    public function call(array $arguments): array
    {
        $project = ($name = $this->string($arguments, 'project')) !== null ? Find::project($name) : null;

        $actions = ProjectAction::query()
            ->with('project')
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))
            ->when($project, fn ($query) => $query->where('project_id', $project?->id))
            ->orderBy('project_id')
            ->orderBy('key')
            ->get();

        return ['actions' => $actions->map(fn (ProjectAction $action) => Describe::action($action))->all()];
    }

    /**
     * The project's action by key or name.
     */
    public static function find(Project $project, string $name): ProjectAction
    {
        $actions = ProjectAction::query()->where('project_id', $project->id);
        $action = (clone $actions)->where('key', $name)->first()
            ?? (clone $actions)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($action === null) {
            $known = $project->actions()->pluck('key')->implode(', ');

            throw new ToolFailed("{$project->name} has no action “{$name}”.".($known !== '' ? " Its actions: {$known}." : ' It has no actions.'));
        }

        return $action;
    }
}
