<?php

namespace App\Terminal;

use App\Actions\Deployments\StartAction;
use App\Actions\Deployments\StartDeployment;
use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Actions\ActionInputs;
use App\Deploy\DeploymentRefused;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\TargetHealth;
use App\Livewire\Projects\Actions;
use App\Mcp\Find;
use App\Mcp\ToolFailed;
use App\Mcp\Tools;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\Server;
use App\Models\Target;
use App\Support\BranchCommits;
use App\Support\Ui;
use App\Support\Updates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The Console theme's shell: the commands you type into its shell pane.
 * Looking (status, runs, servers, projects) prints; acting (deploy, rollback,
 * cancel) goes through the same tools as the MCP server — and so through
 * StartDeployment and CancelDeployment, like the pages — and always asks
 * first. A command missing an argument asks for it: deploy with nothing
 * after it walks through the project, the server, the commit and the backup,
 * then shows the plan and waits for a yes. run does the same for an action:
 * each input it asks for, then the plan, then the yes.
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
        'actions' => 'actions <project> [--history]',
        'run' => 'run <action> --on <server> [name=value…] [--yes: defaults for the rest]',
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
        } elseif (str_starts_with($key, 'input:')) {
            $flags[$key] = $answer;
        } elseif (str_starts_with($key, 'secret:')) {
            // Asked at the prompt, never typed on the command line: kept
            // encrypted in the question's state until the run takes it.
            $flags[$key] = Crypt::encryptString($answer);
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
            'actions' => self::projectNames(),
            'run' => count($parts) === 2 ? self::actionNames() : (($parts[count($parts) - 2] ?? '') === '--on' ? self::serverNames() : ['--on']),
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
                'actions' => $this->actions($args, $flags),
                'run' => $this->runAction($args, $flags),
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

        $output = (new Output)
            ->table(['', 'TARGET', 'LIVE', 'HEALTH', 'LAST RUN'], $rows)
            ->line([' '.$targets->count().' targets · '.$running.' running · '.$failing.' failing', 't-mute']);

        if ($update = Updates::available()) {
            $output->line([' ↑ deploy.me '.$update['version'].' is out (running '.Updates::current().') · run bin/update on this machine', 't-warn']);
        }

        return $output;
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
        $migrates = StartDeployment::migrates($project);

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

        if (StartDeployment::canBackUp($project) && ! isset($flags['backup']) && ! isset($flags['no-backup'])) {
            return self::pick('Back up the database before migrating?', $state + ['flags' => $flags, 'key' => 'flag:backup'], [
                ['value' => 'yes', 'label' => 'yes, pg_dump first', 'hint' => 'keeps '.$project->backup_keep],
                ['value' => 'no', 'label' => 'no'],
            ]);
        }

        $commit = (string) $flags['commit'];
        $backup = StartDeployment::canBackUp($project) && ! isset($flags['no-backup']) && ($flags['backup'] ?? 'yes') !== 'no';

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

            if ($projects->isEmpty()) {
                return (new Output)->error('Nothing is live yet, so there’s nothing to roll back.');
            }

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
     * @param  array<string, string|bool>  $flags
     */
    private function actions(array $args, array $flags): Output
    {
        if (($args[0] ?? '') === '') {
            return (new Output)->error(self::COMMANDS['actions']);
        }

        $project = Find::project($args[0]);

        if (isset($flags['history'])) {
            $runs = Deployment::query()->where('project_id', $project->id)->where('kind', DeploymentKind::Action)->with('server')->latest('id')->limit(12)->get();

            if ($runs->isEmpty()) {
                return (new Output)->line(["No action of {$project->slug}’s has run yet.", 't-mute']);
            }

            return (new Output)->table(['RUN', 'ACTION', 'STATUS', 'ON', 'INPUTS', 'BY'], $runs->map(fn (Deployment $run) => [
                ['#'.$run->id, 't-b'],
                (string) ($run->options['action'] ?? $run->title()),
                [$run->status->value, self::tone($run->status)],
                (string) $run->onServer()?->slug,
                [ActionInputs::line((array) ($run->options['shown'] ?? [])), 't-mute'],
                [Actions::startedBy($run), 't-mute'],
            ])->all());
        }

        $actions = $project->actions()->with(['latestRun.server'])->get();

        if ($actions->isEmpty()) {
            return (new Output)->line(["{$project->slug} has no actions. Commit YAML files to .deployme/actions/, or make one on the project’s Actions tab.", 't-mute']);
        }

        return (new Output)->table(['ACTION', 'ON', 'STEPS', 'FROM', 'LAST RUN'], $actions->map(function (ProjectAction $action) {
            $definition = $action->definition();
            $last = $action->latestRun;

            return [
                [$action->key.($definition?->passkey() ? ' ⚿' : ''), 't-b'],
                $definition !== null ? [implode(' · ', array_filter([$definition->manual() ? 'manual' : null, $definition->scheduleLabel(), $definition->afterDeploy() !== null ? 'after deploy' : null])), ''] : ['errors', 't-err'],
                $definition !== null ? (string) count($definition->steps()) : '—',
                [$action->fromRepo() ? 'repo' : 'here', 't-mute'],
                $last !== null ? ['#'.$last->id.' '.self::mark($last->status).' '.$last->onServer()?->slug, self::tone($last->status)] : ['never', 't-faint'],
            ];
        })->all())->line([' run <action> --on <server> starts one · actions '.$project->slug.' --history lists their runs', 't-faint']);
    }

    /**
     * run <action> --on <server> [name=value…]: asks for the inputs it isn't
     * given (--yes takes the defaults instead), shows what will run where,
     * and starts it on a yes.
     *
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $flags
     */
    private function runAction(array $args, array $flags): Output
    {
        $state = ['command' => 'run', 'args' => $args, 'flags' => $flags];
        $ref = (string) ($args[0] ?? '');
        $given = [];

        foreach (array_slice($args, 1) as $word) {
            if (str_contains($word, '=')) {
                [$name, $value] = explode('=', $word, 2);
                $given[$name] = $value;
            }
        }

        if ($ref === '') {
            $names = self::actionNames(manualOnly: true);

            return $names === []
                ? (new Output)->error('No action can be started by hand yet.')
                : self::pick('Action', $state + ['key' => '0'], array_map(fn (string $name) => ['value' => $name, 'label' => $name], $names));
        }

        $action = $this->findAction($ref, is_string($flags['on'] ?? null) ? $flags['on'] : null, $state);

        if ($action instanceof Output) {
            return $action;
        }

        $project = $action->project;
        $definition = $action->definition() ?? throw new ToolFailed("{$action->key} has errors, so it can’t run: ".implode(' ', $action->errors ?? []));
        $ref = "{$project->slug}/{$action->key}";
        $state['args'] = [$ref, ...array_slice($args, 1)];

        if (! is_string($flags['on'] ?? null)) {
            $targets = $definition->allowedTargets($project);

            if ($targets->count() !== 1) {
                return $targets->isEmpty()
                    ? (new Output)->error("{$action->key} runs on none of the servers {$project->slug} is on.")
                    : self::pick('On which server?', $state + ['key' => 'flag:on'], $targets->map(fn (Target $target) => ['value' => $target->onServer()->slug, 'label' => $target->onServer()->slug, 'hint' => $target->onServer()->env_tag->label()])->values()->all());
            }

            $flags['on'] = $targets->first()->onServer()->slug;
            $state['flags'] = $flags;
        }

        $target = Find::target($project, (string) $flags['on']);
        $confirmed = time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);

        if ($definition->passkey() && ! $confirmed) {
            return (new Output)->error("{$action->key} asks for your passkey: run it from the project’s Actions tab (go project {$project->slug}), which asks for it.");
        }

        // Every input it takes: given, asked, or (with --yes) its default.
        $values = [];
        foreach ($definition->inputs() as $input) {
            $name = $input['name'];

            if ($input['type'] === 'secret') {
                if (array_key_exists($name, $given)) {
                    return (new Output)->error("{$name} is a secret: leave it off the command line, where the screen and the history keep it. run asks for it.");
                }

                if (isset($flags["secret:{$name}"])) {
                    $values[$name] = Crypt::decryptString((string) $flags["secret:{$name}"]);

                    continue;
                }

                $out = new Output;
                $out->ask = ['type' => 'text', 'secret' => true, 'question' => $name.($input['description'] ? ' — '.$input['description'] : ''), 'state' => $state + ['flags' => $flags, 'key' => "secret:{$name}"]];

                return $out;
            }

            $value = $given[$name] ?? $flags["input:{$name}"] ?? null;

            if ($value === null && isset($flags['defaults']) && $input['default'] !== null) {
                $value = $input['default'];
            }

            if ($value === null) {
                $question = $state + ['flags' => $flags, 'key' => "input:{$name}"];

                if ($input['type'] === 'choice' || $input['type'] === 'boolean') {
                    $options = $input['type'] === 'choice' ? $input['options'] : ['no', 'yes'];
                    $default = $input['type'] === 'boolean' ? ($input['default'] ? 'yes' : 'no') : (string) $input['default'];
                    usort($options, fn (string $a, string $b) => ($b === $default) <=> ($a === $default));

                    return self::pick($name.($input['description'] ? ' — '.$input['description'] : ''), $question, array_map(fn (string $option) => [
                        'value' => $option, 'label' => $option, 'hint' => $option === $default ? 'default' : '',
                    ], $options));
                }

                $out = new Output;
                $out->ask = ['type' => 'text', 'question' => $name.($input['description'] ? ' — '.$input['description'] : '').($input['default'] !== null ? " [{$input['default']}]" : ''), 'state' => $question];

                return $out;
            }

            $values[$name] = $value;
        }

        // An answer left empty takes the default.
        foreach ($values as $name => $value) {
            if ($value === '' && ($default = $definition->input($name)['default'] ?? null) !== null) {
                $values[$name] = $default;
            }
        }

        try {
            $resolved = ActionInputs::resolve($definition, $values);
        } catch (DeploymentRefused $e) {
            return (new Output)->error($e->getMessage());
        }

        if (! ($flags['yes'] ?? false)) {
            $rows = [
                [[$definition->name().' → '.$target->onServer()->name, 't-b'], ['  '.StartAction::folder($target), 't-mute']],
                [[$action->fromRepo() ? 'from '.$action->file.($action->commit_sha ? ' @ '.substr($action->commit_sha, 0, 7) : '') : 'made here', 't-mute']],
            ];
            foreach ($definition->steps() as $i => $step) {
                $skipped = $step['if'] !== null && ! ($resolved[$step['if']] ?? false);
                $rows[] = [[($i + 1).'  ', 't-mute'], [str_pad(mb_strimwidth($step['name'], 0, 18, '…'), 19), $skipped ? 't-faint' : ''], [str_pad(ActionDefinition::where($step), 16), 't-mute'], [$skipped ? 'skipped' : $step['run'], $skipped ? 't-faint' : '']];
            }
            $rows[] = [['env   ', 't-mute'], [ActionInputs::line(array_combine(
                array_map(fn (string $name) => ActionDefinition::envName($name), array_keys(ActionInputs::display($definition, $resolved))),
                ActionInputs::display($definition, $resolved),
            ) ?: []), ''], ['  over stdin', 't-faint']];
            $rows[] = [['lock  ', 't-mute'], ['the folder’s deploy lock: refused while a deploy runs', 't-mute']];

            $out = (new Output)->box('plan', $rows, 76);
            $env = Str::lower($target->onServer()->env_tag->label());
            $out->ask = ['type' => 'confirm', 'question' => "Run it on {$target->onServer()->slug} ({$env})?", 'default' => 'n', 'state' => $state + ['flags' => $flags, 'key' => 'confirm']];

            return $out;
        }

        $tool = app(Tools\RunAction::class);
        $tool->trigger = 'console';
        $tool->identityConfirmed = $confirmed;
        $run = $tool->call(['project' => $project->slug, 'action' => $action->key, 'server' => $target->onServer()->slug, 'inputs' => $values]);

        $out = (new Output)->line(['▶ ', 't-ok'], 'started ', ['#'.$run['run'], 't-run'], [' '.$action->key.' on '.$target->onServer()->slug.' · it opens in a pane', 't-mute']);
        $out->open = ['kind' => 'tail', 'arg' => (string) $run['run']];

        return $out;
    }

    /**
     * The action a name means: project/action, or an action's key that only
     * one project (or only one on the server named) has. Otherwise a pick.
     *
     * @param  array<string, mixed>  $state
     */
    private function findAction(string $ref, ?string $server, array $state): ProjectAction|Output
    {
        if (str_contains($ref, '/')) {
            [$project, $key] = explode('/', $ref, 2);

            return Tools\ListActions::find(Find::project($project), $key);
        }

        $found = ProjectAction::query()->where('key', $ref)->with('project')
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))->get();

        if ($found->count() > 1 && $server !== null) {
            $id = Find::server($server)->id;
            $found = $found->filter(fn (ProjectAction $action) => Target::query()->active()->where('project_id', $action->project_id)->where('server_id', $id)->exists())->values();
        }

        return match ($found->count()) {
            0 => throw new ToolFailed("No action “{$ref}”. Try actions <project>."),
            1 => $found->first(),
            default => self::pick('Which project’s?', $state + ['key' => '0'], $found->map(fn (ProjectAction $action) => ['value' => "{$action->project->slug}/{$action->key}", 'label' => "{$action->project->slug}/{$action->key}"])->all()),
        };
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

        // The panes look up a slug (or a run's id): what the name found, not
        // the name as it was typed.
        $arg = ($args[1] ?? null) === null ? null : match ($kind) {
            'tail' => (string) Find::deployment($args[1])->id,
            'htop' => Find::server($args[1])->slug,
            'git' => Find::project($args[1])->slug,
            default => ltrim($args[1], '#'),
        };

        $out = (new Output)->line(['▸ ', 't-run'], 'opened ', [$kind.($arg !== null ? ' '.$arg : ''), 't-b']);
        $out->open = ['kind' => $kind, 'arg' => $arg];

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
                if ($value === null && in_array($name, ['commit', 'limit', 'on'], true) && isset($words[$i + 1])) {
                    $value = $words[++$i];
                }
                $flags[$name] = $value ?? true;
            } elseif ($word === '-y') {
                $flags['yes'] = true;
            } else {
                $args[] = $word;
            }
        }

        // Confirming is always the prompt's job: no flag skips it. For run,
        // --yes means "take the defaults" for the inputs it isn't given.
        if (isset($flags['yes'])) {
            $flags['defaults'] = true;
        }
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
     * Every action as project/key, and the keys only one project has on their own.
     *
     * @return list<string>
     */
    private static function actionNames(bool $manualOnly = false): array
    {
        $actions = ProjectAction::query()->with('project')->whereHas('project', fn ($query) => $query->whereNull('archived_at'))->orderBy('key')->get()
            ->filter(fn (ProjectAction $action) => ! $manualOnly || ($action->definition()?->manual() ?? false));
        $keys = $actions->countBy('key');

        return $actions->flatMap(fn (ProjectAction $action) => $keys[$action->key] === 1 ? [$action->key] : ["{$action->project->slug}/{$action->key}"])->values()->all();
    }

    /**
     * @return list<string>
     */
    private static function serverNames(): array
    {
        return Server::query()->active()->orderBy('slug')->pluck('slug')->all();
    }
}
