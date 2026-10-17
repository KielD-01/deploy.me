<?php

namespace App\Mcp\Tools;

use App\Deploy\Delivery\DeliveryResolver;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;

class PlanDeploy extends Tool
{
    public function name(): string
    {
        return 'plan_deploy';
    }

    public function description(): string
    {
        return 'What a deploy of a project would ship, without starting one: the delivery strategy that applies to the commit (or that it would wait for, CI still running) and how every strategy in the priority list answered. It asks GitHub and ghcr.io; it changes nothing.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name.'],
            'commit' => ['type' => 'string', 'description' => 'A commit sha (7–40 hex). Empty: the branch’s latest commit, or the folder as it is.'],
        ];
    }

    public function required(): array
    {
        return ['project'];
    }

    public function call(array $arguments): array
    {
        $project = Find::project($this->requiredString($arguments, 'project'));
        $commit = self::commit($this->string($arguments, 'commit'));
        $resolution = app(DeliveryResolver::class)->resolve($project, $commit);
        $verdict = $resolution->verdict ?? $resolution->pending;

        return [
            'project' => $project->slug,
            'strategy' => $resolution->strategy?->value,
            'waits_for' => $resolution->waiting() ? $resolution->pendingStrategy?->value : null,
            'fallback_without_waiting' => $resolution->waiting() ? $resolution->fallback()?->value : null,
            'commit' => $verdict?->commit ?? $commit,
            'message' => $verdict?->commitMessage,
            'reason' => $resolution->found() || $resolution->waiting() ? $verdict?->reason : $resolution->reason(),
            'trace' => $resolution->trace,
        ];
    }

    public static function commit(?string $commit): ?string
    {
        if ($commit !== null && preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
            throw new ToolFailed("“{$commit}” isn’t a commit sha (7–40 lowercase hex characters).");
        }

        return $commit;
    }
}
