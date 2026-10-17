<?php

namespace App\Support;

use App\Enums\CiState;
use App\Platform\Detection\Detection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "What we found": one line per finding, for the import page and a project's
 * settings. Tones: ok, warning, info.
 */
final class DetectionReport
{
    /**
     * @return list<array{icon: string, tone: string, title: string, detail: string}>
     */
    public static function items(Detection $detection): array
    {
        $local = ($detection->data['source'] ?? 'local') === 'local';

        return array_values(array_filter([
            ...($local ? self::localGit($detection) : self::githubRepository($detection)),
            ...self::workflows($detection),
            self::compose($detection),
            self::framework($detection),
            $detection->truncated() ? self::item('info', 'info', 'Large repository', 'Only part of the file tree was read, so something may be missing.') : null,
        ]));
    }

    /**
     * @return list<array|null>
     */
    private static function localGit(Detection $detection): array
    {
        $git = $detection->git();
        $github = $detection->github();

        if ($git === null) {
            return [self::item('folder', 'warning', 'Not a git repository', 'Deploys upload the folder as it is, with rsync.')];
        }

        $facts = [$git['commits'].' '.Str::plural('commit', $git['commits'])];
        if ($git['dirty'] > 0) {
            $facts[] = $git['dirty'].' uncommitted '.Str::plural('file', $git['dirty']).', which a deploy won’t include';
        }
        if (($git['ahead'] ?? 0) > 0) {
            $facts[] = $git['ahead'].' not pushed';
        }
        if (($git['behind'] ?? 0) > 0) {
            $facts[] = $git['behind'].' behind '.$git['upstream'];
        }

        return [
            self::item('branch', 'ok', 'Git repository on '.($git['branch'] ?? 'a detached HEAD'), implode(' · ', $facts)),
            $github !== null
                ? self::item('repo', 'ok', "GitHub: {$github['owner']}/{$github['repo']}", 'The '.$github['remote'].' remote, over '
                    .($github['transport'] === 'alias' ? "ssh with the {$github['alias']} identity" : $github['transport']).'.')
                : self::item('repo', 'warning', 'No GitHub remote', 'CI status and the GHCR strategy need the repository on GitHub.'),
        ];
    }

    /**
     * @return list<array|null>
     */
    private static function githubRepository(Detection $detection): array
    {
        $github = $detection->github();
        if ($github === null) {
            return [];
        }

        $head = $github['head'] ?? null;
        $commits = (int) ($github['commits'] ?? 0);
        $ci = isset($github['ci']['state']) ? CiState::tryFrom($github['ci']['state']) : null;
        $run = $github['ci']['run'] ?? null;

        return [
            self::item('branch', 'ok', "Branch {$github['branch']}", $commits.' '.Str::plural('commit', $commits)
                .($head !== null && ! empty($head['date']) ? ' · last one '.Carbon::parse($head['date'])->diffForHumans() : '')),
            $ci !== null && $ci->hasSignal()
                ? self::item($ci->icon(), $ci === CiState::Failing ? 'warning' : 'ok', 'CI '.Str::lower($ci->label()),
                    trim(($run['name'] ?? 'Latest run').' #'.($run['number'] ?? '').' on '.$github['branch']))
                : null,
        ];
    }

    /**
     * @return list<array|null>
     */
    private static function workflows(Detection $detection): array
    {
        $workflows = $detection->workflows();

        if ($workflows === []) {
            return [self::item('ci', 'info', 'No workflows', 'Nothing runs on GitHub Actions, so there’s no CI to wait for.')];
        }

        $items = [];
        foreach ($workflows as $workflow) {
            if ($workflow['error'] !== null) {
                $items[] = self::item('alert', 'warning', "Workflow {$workflow['file']}", $workflow['error']);

                continue;
            }

            $parts = [self::triggers($workflow['triggers'])];
            foreach ($workflow['images'] as $image) {
                $parts[] = 'pushes '.($image['count'] > 1 ? $image['count'].' images like ' : '').$image['image'].':'.$image['tag'];
            }
            $items[] = self::item('ci', 'ok', "Workflow {$workflow['file']}", $workflow['name'].': '.implode('; ', array_filter($parts)).'.');
        }

        if ($detection->imagePublishing() === null) {
            $items[] = self::item('tag', 'info', 'No image publishing', 'No workflow pushes to ghcr.io, so the GHCR strategy is unavailable.');
        }

        foreach ($detection->ciDeploys() as $deploy) {
            $items[] = self::item('alert', 'warning', "{$deploy['workflow']} deploys over SSH too", ($deploy['automatic']
                ? 'It runs '.($deploy['script'] ?? 'a deploy').' on every push to '.implode(', ', $deploy['branches'] ?? ['a branch']).', so deploy.me and CI would race each other.'
                : 'It runs '.($deploy['script'] ?? 'a deploy').' when started by hand.'));
        }

        return $items;
    }

    private static function compose(Detection $detection): array
    {
        $files = $detection->composeFiles();

        if ($files === []) {
            return self::item('box', 'info', 'No Compose file', 'The project deploys without Docker unless you add one.');
        }

        $primary = $files[0];
        $facts = [];
        if ($primary['name'] !== null) {
            $facts[] = "Project name {$primary['name']}";
        }
        if ($primary['services'] !== []) {
            $facts[] = count($primary['services']).' '.Str::plural('service', count($primary['services'])).': '.implode(', ', array_slice($primary['services'], 0, 6))
                .(count($primary['services']) > 6 ? '…' : '');
        }
        if ($primary['pins'] !== []) {
            $facts[] = 'image '.Str::plural('pin', count($primary['pins'])).' '.implode(', ', array_column($primary['pins'], 'var'));
        }
        if (($dockerfile = $detection->dockerfiles()[0] ?? null) !== null) {
            $facts[] = 'Dockerfile in '.(dirname($dockerfile) === '.' ? 'the root' : dirname($dockerfile));
        }

        $title = 'Compose: '.implode(', ', array_column(array_slice($files, 0, 3), 'file')).(count($files) > 3 ? ' and '.(count($files) - 3).' more' : '');

        return $primary['error'] !== null
            ? self::item('box', 'warning', $title, $primary['file'].': '.$primary['error'])
            : self::item('box', 'ok', $title, implode(' · ', $facts) ?: 'No services.');
    }

    private static function framework(Detection $detection): array
    {
        $framework = $detection->framework();

        if ($framework === null) {
            return self::item('layers', 'info', 'No framework recognised', 'Hooks and health checks are up to you.');
        }

        $facts = $framework['extras'];
        foreach ($detection->envTemplates() as $template) {
            if (str_starts_with($template['path'], $framework['path'])) {
                $facts[] = basename($template['path']).' with '.$template['keys'].' '.Str::plural('key', $template['keys']);
                break;
            }
        }
        $others = array_slice($detection->apps(), 1);
        if ($others !== []) {
            $facts[] = 'also '.implode(', ', array_map(fn (array $app) => $app['name'].' in '.($app['path'] ?: 'the root'), $others));
        }

        return self::item('layers', 'ok', $detection->frameworkLabel().' in '.($framework['path'] ?: 'the root'), implode(' · ', $facts) ?: $framework['name'].' app');
    }

    private static function triggers(array $triggers): string
    {
        $on = [];
        if ($triggers['push'] !== null) {
            $on[] = $triggers['push'] === ['*'] ? 'every push' : 'pushes to '.implode(', ', $triggers['push']);
        }
        if ($triggers['pull_request']) {
            $on[] = 'pull requests';
        }
        if ($triggers['manual']) {
            $on[] = 'by hand';
        }
        if ($triggers['schedule']) {
            $on[] = 'on a schedule';
        }

        return $on === [] ? '' : 'runs on '.implode(', ', $on);
    }

    /**
     * @return array{icon: string, tone: string, title: string, detail: string}
     */
    private static function item(string $icon, string $tone, string $title, string $detail): array
    {
        return compact('icon', 'tone', 'title', 'detail');
    }
}
