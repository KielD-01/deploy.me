<?php

namespace App\Terminal;

use App\Deploy\Compose;
use App\Deploy\Pipeline\Steps\BackupDatabase;
use App\Enums\DeploymentStatus;
use App\Enums\TargetHealth;
use App\Mcp\Find;
use App\Mcp\ToolFailed;
use App\Mcp\Tools;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Support\BranchCommits;
use App\Support\Ui;
use Illuminate\Support\Carbon;

/**
 * The Console theme's shell: the commands you type into its shell pane.
 * Looking (status, runs, servers, projects) prints; acting (deploy, rollback,
 * cancel) goes through the same tools as the MCP server — and so through
 * StartDeployment and CancelDeployment, like the pages — and always asks
 * first. A command missing an argument asks for it: deploy with nothing
 * after it walks through the project, the server, the commit and the backup,
 * then shows the plan and waits for a yes.
 */
final class Shell
{
    public const COMMANDS = [
        'help' => 'this list',
        'status' => 'every target: live commit, health, last run',
        'runs' => 'recent runs · runs <project>',
        'servers' => 'servers, their status and load',
        'projects' => 'projects, their branch and CI',
        'deploy' => 'deploy <project> <server> [--commit <sha>] [--no-backup] [--skip-migrations]',
        'rollback' => 'rollback <project> [<server>] [<release>]',
        'cancel' => 'cancel <run>',
        'probe' => 'probe <server>',
        'open' => 'open tail <run> · htop <server> · git <project> · status — in a pane',
        'go' => 'go runs · servers · projects · audit · settings · run <n> · server <name> · project <name>',
        'theme' => 'theme console · classic',
        'clear' => 'clear the screen',
    ];

    /**
     * Runs a line typed at the prompt.
     */
    public function run(string $input): Output
    {
        [$command, $args, $flags] = self::parse($input);

        return $this->dispatch($command, $args, $flags);
    }

    /**
     * Continues a command that asked something, with the answer.
     *
     * @param  array<string, mixed>  $state  what the question carried: command, args, flags, key
     */
    public function answer(array $state, string $answer): Output
    {
        $args = $state['args'] ?? [];
        $flags = $state['flags'] ?? [];
        $key = (string) ($state['key'] ?? '');

        if ($key === 'confirm') {
            if (! in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                return (new Output)->line(['Nothing done.', 't-mute']);
            }
            $flags['yes'] = true;
        } elseif (str_starts_with($key, 'flag:')) {
            $flags[substr($key, 5)] = $answer;
        } else {
            $args[(int) $key] = $answer;
        }

        return $this->dispatch((string) ($state['command'] ?? ''), $args, $flags);
    }

    /**
     * Completions for what's typed so far: commands, then names.
     *
     * @return list<string>
     */
    public function complete(string $input): array
    {
        $parts = preg_split('/\s+/', ltrim($input)) ?: [];
        $last = (string) end($parts);

        if (count($parts) <= 1) {
            return array_values(array_filter(array_keys(self::COMMANDS), fn (string $name) => str_starts_with($name, $last)));
        }

        $names = match ($parts[0]) {
            'deploy', 'rollback', 'runs' => count($parts) === 2 ? self::projectNames() : self::serverNames(),
            'probe' => self::serverNames(),
            'open' => count($parts) === 2 ? ['status', 'tail', 'htop', 'git'] : match ($parts[1]) {
                'htop' => self::serverNames(),
                'git' => self::projectNames(),
                default => [],
            },
            'go' => count($parts) === 2 ? ['runs', 'servers', 'projects', 'audit', 'settings', 'run', 'server', 'project'] : match ($parts[1]) {
                'server' => self::serverNames(),
                'project' => self::projectNames(),
                default => [],
            },
            'theme' => array_keys(Ui::THEMES),
            default => [],
        };

        $prefix = implode(' ', array_slice($parts, 0, -1));

        return array_values(array_map(fn (string $name) => "{$prefix} {$name}", array_filter($names, fn (string $name) => str_starts_with($name, $last))));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function dispatch(string $command, array $args, array $flags): Output
    {
        try {
            return match ($command) {
                '' => new Output,
                'help', '?' => $this->help(),
                'status', 'st' => $this->status(),
                'runs' => $this->runs($args, $flags),
                'servers' => $this->servers(),
                'projects' => $this->projects(),
                'deploy' => $this->deploy($args, $flags),
                'rollback' => $this->rollback($args, $flags),
                'cancel' => $this->cancel($args, $flags),
                'probe' => $this->probe($args),
                'open' => $this->open($args),
                'go' => $this->go($args),
                'theme' => $this->theme($args),
                'clear', 'cls' => tap(new Output, fn (Output $out) => $out->clear = true),
                default => (new Output)->error("{$command}: no such command. Type help for the list."),
            };
        } catch (ToolFailed $e) {
            return (new Output)->error($e->getMessage());
        }
    }

    private function help(): Output
    {
        $out = new Output;
        foreach (self::COMMANDS as $name => $what) {
            $out->line(' ', [str_pad($name, 10), 't-b'], [$what, 't-mute']);
        }

        return $out->blank()
            ->line([' Tab completes names. Anything that changes a server shows what it will do and asks first.', 't-faint'])
            ->line([' Panes: ⌃b % split right · ⌃b " split down · ⌃b z zoom · ⌃b x close · ⌃b ? all keys', 't-faint']);
    }

    private function status(): Output
    {
        $targets = Target::query()->active()
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))
            ->whereHas('server', fn ($query) => $query->whereNull('archived_at'))
            ->with(['project', 'server', 'currentRelease', 'latestDeployment', 'healthChecks' => fn ($query) => $query->latest('checked_at')->limit(16)])
            ->get()
            ->sortBy(fn (Target $target) => $target->project->name.'@'.$target->server->name);

        if ($targets->isEmpty()) {
            return (new Output)->line(['No targets yet. Assign a project to a server on the project’s Targets tab.', 't-mute']);
        }

        $rows = $targets->map(function (Target $target) {
            $run = $target->latestDeployment;
            $active = $run?->isActive() ?? false;
            $tone = $active ? 't-run' : match ($target->health) {
                TargetHealth::Healthy => 't-ok',
                TargetHealth::Unhealthy => 't-err',
                default => 't-faint',
            };
            $spark = $target->healthChecks->reverse()->map(fn ($check) => $check->health === TargetHealth::Unhealthy ? '╳' : '│')->implode('');

            return [
                ['●', $tone],
                $target->project->slug.'@'.$target->server->slug,
                [$target->currentRelease?->shortSha() ?? '—', $target->currentRelease ? '' : 't-faint'],
                [$spark !== '' ? $spark : 'no checks yet', $spark !== '' && str_contains($spark, '╳') ? 't-warn' : 't-ok'],
                [$active ? '#'.$run->id.' ▶ '.$run->status->label() : ($run ? '#'.$run->id.' '.self::mark($run->status) : '—'), $active ? 't-run' : self::tone($run?->status)],
            ];
        })->values()->all();

        $running = $targets->filter(fn (Target $target) => $target->latestDeployment?->isActive())->count();
        $failing = $targets->where('health', TargetHealth::Unhealthy)->count();

        return (new Output)
            ->table(['', 'TARGET', 'LIVE', 'HEALTH', 'LAST RUN'], $rows)
            ->line([' '.$targets->count().' targets · '.$running.' running · '.$failing.' failing', 't-mute']);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function runs(array $args, array $flags): Output
    {
        $list = app(Tools\ListDeployments::class)->call(array_filter([
            'project' => $args[0] ?? null,
            'limit' => (int) ($flags['limit'] ?? 12),
        ]))['deployments'];

        if ($list === []) {
            return (new Output)->line(['No runs yet.', 't-mute']);
        }

        return (new Output)->table(['RUN', 'WHAT', 'STATUS', 'COMMIT', 'TOOK'], array_map(fn (array $run) => [
            ['#'.$run['run'], 't-b'],
            $run['kind'].' '.$run['project'].' → '.$run['server'],
            [$run['status'], self::tone(DeploymentStatus::tryFrom($run['status']))],
            [substr((string) $run['commit'], 0, 7), 't-mute'],
            [(string) ($run['duration'] ?? '—'), 't-mute'],
        ], $list));
    }

    private function servers(): Output
    {
        $servers = app(Tools\ListServers::class)->call([])['servers'];

        if ($servers === []) {
            return (new Output)->line(['No servers yet. Add one from your ~/.ssh/config: go servers.', 't-mute']);
        }

        return (new Output)->table(['', 'SERVER', 'ENV', 'CPU', 'MEM', 'PROBED'], array_map(fn (array $server) => [
            ['●', match ($server['status']) {
                'online' => 't-ok', 'degraded' => 't-warn', 'offline' => 't-err', default => 't-faint'
            }],
            [$server['slug'], 't-b'],
            [(string) $server['environment'], 't-mute'],
            $server['cpu_percent'] !== null ? round($server['cpu_percent']).'%' : '—',
            $server['memory_used_percent'] !== null ? round($server['memory_used_percent']).'%' : '—',
            [$server['probed_at'] ? Carbon::parse($server['probed_at'])->diffForHumans(short: true) : 'never', 't-mute'],
        ], $servers));
    }

    private function projects(): Output
    {
        $projects = app(Tools\ListProjects::class)->call([])['projects'];

        if ($projects === []) {
            return (new Output)->line(['No projects yet: go projects, then Import project.', 't-mute']);
        }

        return (new Output)->table(['PROJECT', 'BRANCH', 'CI', 'TARGETS'], array_map(fn (array $project) => [
            [$project['slug'], 't-b'],
            [$project['branch'], 't-mute'],
            [(string) $project['ci'], match ($project['ci']) {
                'passing' => 't-ok', 'failing' => 't-err', 'running' => 't-run', default => 't-faint'
            }],
            implode(', ', array_map(fn (array $target) => $target['server'], $project['targets'])) ?: '—',
        ], $projects));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function deploy(array $args, array $flags): Output
    {
        $state = ['command' => 'deploy', 'args' => $args, 'flags' => $flags];

        if (($args[0] ?? '') === '') {
            $projects = Project::query()->active()->whereHas('targets', fn ($query) => $query->active())->orderBy('name')->get();

            if ($projects->isEmpty()) {
                return (new Output)->error('No project is assigned to a server yet.');
            }

            return self::pick('Project', $state + ['key' => '0'], $projects->map(fn (Project $project) => ['value' => $project->slug, 'label' => $project->slug, 'hint' => $project->branch])->all());
        }

        $project = Find::project($args[0]);
        $targets = $project->targets()->active()->with('server')->orderBy('id')->get();

        if (($args[1] ?? '') === '' && $targets->count() > 1) {
            return self::pick('Server', $state + ['key' => '1'], $targets->map(fn (Target $target) => ['value' => $target->server->slug, 'label' => $target->server->slug, 'hint' => $target->path])->all());
        }

        $target = Find::target($project, $args[1] ?? null);
        $migrates = Compose::builtin($project) && (string) $project->migrate_service !== '';

        if (! isset($flags['commit'])) {
            $commits = BranchCommits::recent($project, 8);
            $live = $target->currentRelease?->commit_sha;

            if ($commits !== []) {
                return self::pick('Commit', $state + ['key' => 'flag:commit'], array_map(fn (array $commit) => [
                    'value' => $commit['sha'],
                    'label' => substr($commit['sha'], 0, 7).'  '.mb_strimwidth($commit['subject'], 0, 48, '…'),
                    'hint' => trim(($commit['migration'] ? '+migration  ' : '').($commit['sha'] === $live ? 'live here' : '')),
                    'tone' => $commit['sha'] === $live ? 't-ok' : '',
                ], $commits));
            }
            $flags['commit'] = '';
        }

        if ($migrates && BackupDatabase::available($project) && ! isset($flags['backup']) && ! isset($flags['no-backup'])) {
            return self::pick('Back up the database before migrating?', $state + ['flags' => $flags, 'key' => 'flag:backup'], [
                ['value' => 'yes', 'label' => 'yes, pg_dump first', 'hint' => 'keeps '.$project->backup_keep],
                ['value' => 'no', 'label' => 'no'],
            ]);
        }

        $commit = (string) $flags['commit'];
        $backup = $migrates && ! isset($flags['no-backup']) && ($flags['backup'] ?? 'yes') !== 'no';

        if (! ($flags['yes'] ?? false)) {
            $plan = app(Tools\PlanDeploy::class)->call(array_filter(['project' => $project->slug, 'commit' => $commit]));
            $live = $target->currentRelease?->shortSha() ?? 'nothing';
            $to = $plan['commit'] ? substr((string) $plan['commit'], 0, 7) : 'the folder as it is';

            $out = (new Output)->box('plan', [
                [[$project->slug.' → '.$target->server->slug, 't-b'], ['   '.$live.' → ', 't-mute'], [$to, 't-run']],
                [['strategy  ', 't-mute'], [$plan['strategy'] ?? ($plan['waits_for'] ? 'waits for '.$plan['waits_for'] : 'none applies'), $plan['strategy'] ? '' : 't-warn']],
                [['why       ', 't-mute'], [(string) $plan['reason'], 't-faint']],
                [['migrate   ', 't-mute'], [$migrates ? (isset($flags['skip-migrations']) ? 'skipped' : 'yes'.($backup ? ', pg_dump first' : ', no backup')) : 'no', $migrates ? 't-warn' : 't-mute']],
                [['safety    ', 't-mute'], [($project->auto_rollback ? 'auto-rollback on' : 'auto-rollback off').' · lock on '.$target->path, 't-mute']],
            ]);

            if ($plan['strategy'] === null && $plan['waits_for'] === null) {
                return $out->error('Nothing can deliver this project right now.');
            }

            $out->ask = ['type' => 'confirm', 'question' => "Deploy {$project->slug} to {$target->server->slug}?", 'default' => 'n', 'state' => ['command' => 'deploy', 'args' => [$project->slug, $target->server->slug], 'flags' => $flags, 'key' => 'confirm']];

            return $out;
        }

        $tool = app(Tools\Deploy::class);
        $tool->trigger = 'console';
        $run = $tool->call(array_filter([
            'project' => $project->slug,
            'server' => $target->server->slug,
            'commit' => $commit,
            'backup' => $backup,
            'skip_migrations' => isset($flags['skip-migrations']),
        ], fn ($value) => $value !== '' && $value !== null));

        $out = (new Output)->line(['▶ ', 't-ok'], 'started ', ['#'.$run['run'], 't-run'], [' · it opens in a pane; ⌃b z zooms it', 't-mute']);
        $out->open = ['kind' => 'tail', 'arg' => (string) $run['run']];

        return $out;
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function rollback(array $args, array $flags): Output
    {
        $state = ['command' => 'rollback', 'args' => $args, 'flags' => $flags];

        if (($args[0] ?? '') === '') {
            $projects = Project::query()->active()->whereHas('targets', fn ($query) => $query->active()->whereNotNull('current_release_id'))->orderBy('name')->get();

            return self::pick('Project', $state + ['key' => '0'], $projects->map(fn (Project $project) => ['value' => $project->slug, 'label' => $project->slug])->all());
        }

        $project = Find::project($args[0]);
        $targets = $project->targets()->active()->with('server')->get();

        if (($args[1] ?? '') === '' && $targets->count() > 1) {
            return self::pick('Server', $state + ['key' => '1'], $targets->map(fn (Target $target) => ['value' => $target->server->slug, 'label' => $target->server->slug])->all());
        }

        $target = Find::target($project, $args[1] ?? null);

        if (($args[2] ?? '') === '') {
            $releases = $target->releases()->latest('id')->limit(8)->get()->filter(fn ($release) => $release->id !== $target->current_release_id && $release->canRollBackTo($project));

            if ($releases->isEmpty()) {
                return (new Output)->error("{$project->slug} on {$target->server->slug} has no earlier release to go back to.");
            }

            return self::pick('Back to which release?', $state + ['args' => [$project->slug, $target->server->slug], 'key' => '2'], $releases->map(fn ($release) => [
                'value' => $release->name,
                'label' => $release->name.'  '.($release->shortSha() ?? ''),
                'hint' => mb_strimwidth((string) $release->commit_message, 0, 40, '…'),
            ])->values()->all());
        }

        if (! ($flags['yes'] ?? false)) {
            $out = (new Output)->line(['↺ ', 't-warn'], [$project->slug.' on '.$target->server->slug, 't-b'], ['  '.($target->currentRelease?->name ?? 'nothing live').' → ', 't-mute'], [$args[2], 't-warn']);
            $out->ask = ['type' => 'confirm', 'question' => "Roll {$project->slug} on {$target->server->slug} back to {$args[2]}?", 'default' => 'n', 'state' => $state + ['args' => [$project->slug, $target->server->slug, $args[2]], 'key' => 'confirm']];

            return $out;
        }

        $tool = app(Tools\Rollback::class);
        $tool->trigger = 'console';
        $run = $tool->call(['project' => $project->slug, 'server' => $target->server->slug, 'release' => $args[2]]);
        $out = (new Output)->line(['↺ ', 't-warn'], 'rolling back · ', ['#'.$run['run'], 't-run']);
        $out->open = ['kind' => 'tail', 'arg' => (string) $run['run']];

        return $out;
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function cancel(array $args, array $flags): Output
    {
        if (($args[0] ?? '') === '') {
            $active = Deployment::query()->whereIn('status', DeploymentStatus::ACTIVE)->with(['project', 'server'])->latest('id')->get();

            if ($active->isEmpty()) {
                return (new Output)->line(['Nothing is running.', 't-mute']);
            }

            return self::pick('Cancel which run?', ['command' => 'cancel', 'args' => [], 'flags' => $flags, 'key' => '0'], $active->map(fn (Deployment $run) => ['value' => (string) $run->id, 'label' => '#'.$run->id.' '.$run->project?->slug.' → '.$run->server?->slug, 'hint' => $run->status->label()])->all());
        }

        $run = Find::deployment($args[0]);

        if (! ($flags['yes'] ?? false)) {
            $out = new Output;
            $out->ask = ['type' => 'confirm', 'question' => "Cancel #{$run->id} ({$run->project?->slug} → {$run->server?->slug})?", 'default' => 'n', 'state' => ['command' => 'cancel', 'args' => [(string) $run->id], 'flags' => $flags, 'key' => 'confirm']];

            return $out;
        }

        $result = app(Tools\CancelDeployment::class)->call(['run' => (string) $run->id]);

        return (new Output)->line(['■ ', 't-warn'], '#'.$result['run'].' ', [$result['status'], self::tone(DeploymentStatus::tryFrom($result['status']))], [' · its cleanup still runs', 't-mute']);
    }

    /**
     * @param  list<string>  $args
     */
    private function probe(array $args): Output
    {
        if (($args[0] ?? '') === '') {
            return (new Output)->error('probe <server>');
        }

        $result = app(Tools\ProbeServer::class)->call(['server' => $args[0]]);

        return (new Output)->line(['● ', 't-run'], 'probing ', [$result['server'], 't-b'], [' · its htop pane updates when it answers', 't-mute']);
    }

    /**
     * @param  list<string>  $args
     */
    private function open(array $args): Output
    {
        $kind = $args[0] ?? '';

        if (! in_array($kind, ['status', 'tail', 'htop', 'git'], true)) {
            return (new Output)->error('open status · tail <run> · htop <server> · git <project>');
        }

        $arg = $args[1] ?? null;
        match ($kind) {
            'tail' => $arg === null ?: Find::deployment($arg),
            'htop' => $arg === null ?: Find::server($arg),
            'git' => $arg === null ?: Find::project($arg),
            default => null,
        };

        $out = (new Output)->line(['▸ ', 't-run'], 'opened ', [$kind.($arg !== null ? ' '.ltrim($arg, '#') : ''), 't-b']);
        $out->open = ['kind' => $kind, 'arg' => $arg !== null ? ltrim($arg, '#') : null];

        return $out;
    }

    /**
     * @param  list<string>  $args
     */
    private function go(array $args): Output
    {
        $out = new Output;
        $out->visit = match ($args[0] ?? '') {
            'runs' => route('deployments.index'),
            'servers' => route('servers.index'),
            'projects' => route('projects.index'),
            'audit' => route('audit'),
            'settings' => route('settings'),
            'run' => route('deployments.show', Find::deployment((string) ($args[1] ?? ''))),
            'server' => route('servers.show', Find::server((string) ($args[1] ?? ''))),
            'project' => route('projects.show', Find::project((string) ($args[1] ?? ''))),
            default => null,
        };

        return $out->visit === null ? $out->error(self::COMMANDS['go']) : $out->line(['→ ', 't-run'], [$out->visit, 't-mute']);
    }

    /**
     * @param  list<string>  $args
     */
    private function theme(array $args): Output
    {
        $theme = $args[0] ?? '';

        if (! array_key_exists($theme, Ui::THEMES)) {
            return (new Output)->error('theme '.implode(' · ', array_keys(Ui::THEMES)));
        }

        Ui::save(auth()->user(), ['theme' => $theme]);
        $out = (new Output)->line(['✓ ', 't-ok'], 'theme ', [$theme, 't-b']);
        $out->visit = route('dashboard');

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array{value: string, label: string, hint?: string, tone?: string}>  $options
     */
    private static function pick(string $question, array $state, array $options): Output
    {
        $out = new Output;
        $out->ask = ['type' => 'choice', 'question' => $question, 'options' => array_values($options), 'state' => $state];

        return $out;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: array<string, string|bool>}
     */
    public static function parse(string $input): array
    {
        $words = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $command = strtolower((string) array_shift($words));
        $args = [];
        $flags = [];

        for ($i = 0; $i < count($words); $i++) {
            $word = $words[$i];

            if (str_starts_with($word, '--')) {
                [$name, $value] = array_pad(explode('=', substr($word, 2), 2), 2, null);
                if ($value === null && in_array($name, ['commit', 'limit'], true) && isset($words[$i + 1])) {
                    $value = $words[++$i];
                }
                $flags[$name] = $value ?? true;
            } elseif ($word === '-y') {
                $flags['yes'] = true;
            } else {
                $args[] = $word;
            }
        }

        // Confirming is always the prompt's job: no flag skips it.
        unset($flags['yes']);

        return [$command, $args, $flags];
    }

    private static function mark(DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Succeeded => '✓',
            DeploymentStatus::Failed, DeploymentStatus::Stalled => '✕',
            DeploymentStatus::RolledBack => '↺',
            DeploymentStatus::Cancelled => '■',
            default => '●',
        };
    }

    private static function tone(?DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Succeeded => 't-ok',
            DeploymentStatus::Failed, DeploymentStatus::Stalled => 't-err',
            DeploymentStatus::RolledBack => 't-warn',
            DeploymentStatus::Running, DeploymentStatus::Queued, DeploymentStatus::WaitingCi => 't-run',
            default => 't-mute',
        };
    }

    /**
     * @return list<string>
     */
    private static function projectNames(): array
    {
        return Project::query()->active()->orderBy('slug')->pluck('slug')->all();
    }

    /**
     * @return list<string>
     */
    private static function serverNames(): array
    {
        return Server::query()->active()->orderBy('slug')->pluck('slug')->all();
    }
}
