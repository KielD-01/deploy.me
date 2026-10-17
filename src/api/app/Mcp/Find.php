<?php

namespace App\Mcp;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;

/**
 * Looks up what a tool call names, the way a person would name it: a
 * project by slug or name, a server by slug, name or ssh alias, a run by its
 * number (with or without the #). A miss says what there is to pick from.
 */
final class Find
{
    public static function project(string $name): Project
    {
        $project = Project::query()->active()->where('slug', $name)->first()
            ?? Project::query()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($project === null) {
            $known = Project::query()->active()->orderBy('slug')->pluck('slug')->implode(', ');

            throw new ToolFailed("No project “{$name}”.".($known !== '' ? " Projects: {$known}." : ' There are no projects yet.'));
        }

        return $project;
    }

    public static function server(string $name): Server
    {
        $server = Server::query()->active()->where('slug', $name)->first()
            ?? Server::query()->active()->where('ssh_alias', $name)->first()
            ?? Server::query()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($server === null) {
            $known = Server::query()->active()->orderBy('slug')->pluck('slug')->implode(', ');

            throw new ToolFailed("No server “{$name}”.".($known !== '' ? " Servers: {$known}." : ' There are no servers yet.'));
        }

        return $server;
    }

    public static function deployment(string $number): Deployment
    {
        $id = ltrim(trim($number), '#');

        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ToolFailed("“{$number}” isn’t a run number (like 42 or #42).");
        }

        return Deployment::query()->find((int) $id) ?? throw new ToolFailed("There is no run #{$id}.");
    }

    /**
     * The project's target on a server, or its only target when no server is named.
     */
    public static function target(Project $project, ?string $server): Target
    {
        // What the tools and the terminal read off the target they get back.
        $targets = $project->targets()->active()->with(['server', 'project', 'currentRelease'])->orderBy('id')->get();

        if ($targets->isEmpty()) {
            throw new ToolFailed("{$project->name} isn’t assigned to a server yet. Assign it on the project’s Targets tab.");
        }

        if ($server === null) {
            if ($targets->count() > 1) {
                throw new ToolFailed("{$project->name} deploys to more than one server; name one: ".$targets->map(fn (Target $target) => $target->server->slug)->implode(', ').'.');
            }

            return $targets->first();
        }

        $id = self::server($server)->id;

        return $targets->firstWhere('server_id', $id)
            ?? throw new ToolFailed("{$project->name} isn’t assigned to {$server}. It deploys to: ".$targets->map(fn (Target $target) => $target->server->slug)->implode(', ').'.');
    }
}
