<?php

namespace App\Actions\Transfer;

use App\Actions\Projects\AssignProject;
use App\Enums\AuditAction;
use App\Enums\CiState;
use App\Enums\ProjectSource;
use App\Enums\ReleaseStatus;
use App\Jobs\PollCiStatus;
use App\Models\Credential;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Local\ProjectRoots;
use App\Support\Audit;
use App\Support\Names;
use App\Support\TargetFolderRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Takes over projects another deploy.me exported (ExportProjects). A project
 * already here — the same slug, repository or folder — is left as it is; only
 * the targets it doesn't have yet are added, so importing again after adding
 * a missing server finishes the job. A target needs its server here, found by
 * its ssh alias. Nothing on any server changes.
 */
class ImportProjects
{
    public function __construct(private readonly AssignProject $assign) {}

    /**
     * What an import would do, project by project, changing nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{slug: string, name: string, source: string, state: string, here: ?string, reason: ?string, env_files: int, hooks: int, actions: int, auto_deploy: bool, notes: list<string>, targets: list<array{server: string, server_name: string, path: string, state: string, reason: ?string}>}>
     */
    public function plan(array $payload): array
    {
        $roots = ProjectRoots::fromConfig();
        $rows = [];

        foreach (array_values($payload['projects']) as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $slug = is_string($entry['slug'] ?? null) ? $entry['slug'] : '';
            $source = ProjectSource::tryFrom((string) ($entry['source'] ?? ''));
            $here = $source !== null && Names::isFolder($slug) ? $this->existing($entry, $roots) : null;

            $row = [
                'slug' => $slug,
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : $slug,
                'source' => $source === ProjectSource::Github ? "{$entry['github_owner']}/{$entry['github_repo']}" : (string) ($entry['local_path'] ?? ''),
                'state' => match (true) {
                    $source === null || ! Names::isFolder($slug) => 'invalid',
                    $here !== null => 'here',
                    default => 'new',
                },
                'here' => $here?->name,
                'reason' => null,
                'env_files' => count($entry['env_files'] ?? []),
                'hooks' => count($entry['hooks'] ?? []),
                'actions' => count($entry['actions'] ?? []),
                'auto_deploy' => (bool) ($entry['auto_deploy'] ?? false),
                'notes' => [],
                'targets' => [],
            ];

            if ($row['state'] === 'invalid') {
                $row['reason'] = 'This entry can’t be read.';
                $rows[] = $row;

                continue;
            }

            if ($row['state'] === 'new') {
                $row['notes'] = $this->notes($entry, $source, $roots);
            }

            foreach (array_values((array) ($entry['targets'] ?? [])) as $target) {
                $row['targets'][] = $this->targetPlan($target, $here);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Creates what the plan says will be created.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $keepAutoDeploy  this machine takes over deploying on green CI
     * @return array{projects: list<string>, targets: int, skipped: list<string>}
     */
    public function __invoke(array $payload, bool $keepAutoDeploy = false): array
    {
        $plan = $this->plan($payload);
        $created = [];
        $targets = 0;
        $skipped = [];

        foreach (array_values($payload['projects']) as $i => $entry) {
            $row = $plan[$i];

            if ($row['state'] === 'invalid') {
                $skipped[] = "{$row['name']}: {$row['reason']}";

                continue;
            }

            try {
                [$project, $added, $refused] = DB::transaction(function () use ($entry, $row, $keepAutoDeploy) {
                    $project = $row['state'] === 'new'
                        ? $this->create($entry, $keepAutoDeploy)
                        : $this->existing($entry, ProjectRoots::fromConfig());

                    [$added, $refused] = $this->addTargets($project, array_values((array) ($entry['targets'] ?? [])), $row['targets']);

                    return [$project, $added, $refused];
                });
            } catch (Throwable $e) {
                report($e);
                $skipped[] = "{$row['name']}: it couldn’t be imported ({$e->getMessage()}).";

                continue;
            }

            if ($row['state'] === 'new') {
                $created[] = $project->slug;

                if ($project->hasGitHub()) {
                    PollCiStatus::dispatch($project->id);
                }
            }

            $targets += $added;
            array_push($skipped, ...array_map(fn (string $reason) => "{$row['name']}: {$reason}", $refused));
        }

        if ($created !== [] || $targets > 0) {
            Audit::record(AuditAction::ProjectsImported, [
                'subject' => count($created).' '.Str::plural('project', count($created)).', '.$targets.' '.Str::plural('target', $targets),
                'detail' => implode(', ', $created) ?: null,
            ]);
        }

        return ['projects' => $created, 'targets' => $targets, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function create(array $entry, bool $keepAutoDeploy): Project
    {
        $fields = array_intersect_key($entry, array_flip(ExportProjects::PROJECT_FIELDS));
        $fields['detected_at'] = self::time($fields['detected_at'] ?? null);
        $fields['local_path'] = isset($fields['local_path']) ? ProjectRoots::fromConfig()->normalize((string) $fields['local_path']) : null;
        // A slug a removed project still holds gets a number.
        $fields['slug'] = Project::query()->where('slug', $entry['slug'])->exists() ? Project::uniqueSlug($entry['slug']) : $entry['slug'];
        $fields['auto_deploy'] = $keepAutoDeploy && ($entry['auto_deploy'] ?? false);

        $project = Project::query()->create([
            ...$fields,
            'credential_id' => isset($fields['github_owner']) ? Credential::forOwner((string) $fields['github_owner'])?->id : null,
            // Asked again from GitHub by the first poll.
            'ci_state' => isset($fields['github_owner'], $fields['github_repo']) ? null : CiState::NoRemote,
        ]);

        foreach ($entry['env_files'] ?? [] as $file) {
            $project->envFiles()->create([
                'path' => $file['path'],
                'role' => $file['role'] ?? 'app',
                ...ProjectEnvFile::contentAttributes((string) $file['content']),
            ]);
        }

        foreach ($entry['hooks'] ?? [] as $hook) {
            $project->hooks()->create(array_intersect_key($hook, array_flip(ExportProjects::HOOK_FIELDS)));
        }

        foreach ($entry['actions'] ?? [] as $action) {
            $values = array_intersect_key($action, array_flip(ExportProjects::ACTION_FIELDS));
            $project->actions()->create([...$values, 'loaded_at' => self::time($values['loaded_at'] ?? null)]);
        }

        return $project;
    }

    /**
     * The targets the plan marked to add, each with the releases still on it.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array{state: string, reason: ?string, server_name: string}>  $plans
     * @return array{int, list<string>}
     */
    private function addTargets(Project $project, array $entries, array $plans): array
    {
        $added = 0;
        $refused = [];

        foreach ($entries as $i => $entry) {
            $plan = $plans[$i];

            if ($plan['state'] !== 'add') {
                if ($plan['state'] !== 'here') {
                    $refused[] = "{$plan['server_name']}: {$plan['reason']}";
                }

                continue;
            }

            $server = Server::query()->active()->where('ssh_alias', $entry['server'])->firstOrFail();

            try {
                $target = ($this->assign)($project, $server, (string) $entry['path'], exact: true);
            } catch (ValidationException $e) {
                $refused[] = "{$server->name}: ".collect($e->errors())->flatten()->first();

                continue;
            }

            $ids = [];

            foreach ($entry['releases'] ?? [] as $release) {
                $values = array_intersect_key($release, array_flip(ExportProjects::RELEASE_FIELDS));
                $ids[$release['name']] = Release::query()->create([
                    ...$values,
                    'target_id' => $target->id,
                    'status' => ReleaseStatus::Ready,
                    'activated_at' => self::time($values['activated_at'] ?? null),
                ])->id;
            }

            $target->update([
                'current_release_id' => $ids[$entry['current'] ?? ''] ?? null,
                'previous_release_id' => $ids[$entry['previous'] ?? ''] ?? null,
                'bootstrapped_at' => self::time($entry['bootstrapped_at'] ?? null),
                'last_deployed_at' => self::time($entry['last_deployed_at'] ?? null),
            ]);
            $added++;
        }

        return [$added, $refused];
    }

    /**
     * @return array{server: string, server_name: string, path: string, state: string, reason: ?string}
     */
    private function targetPlan(mixed $target, ?Project $here): array
    {
        $target = is_array($target) ? $target : [];
        $alias = (string) ($target['server'] ?? '');
        $path = TargetFolderRules::normalize((string) ($target['path'] ?? ''));
        $server = $alias !== '' ? Server::query()->active()->where('ssh_alias', $alias)->first() : null;
        $row = ['server' => $alias, 'server_name' => $server->name ?? (string) ($target['server_name'] ?? $alias), 'path' => $path, 'state' => 'add', 'reason' => null];

        if ($server === null) {
            return [...$row, 'state' => 'no_server', 'reason' => "No server with the ssh alias {$alias} here. Add it on the Servers page (from ~/.ssh/config), then import again."];
        }

        if ($here !== null && Target::query()->active()->where('project_id', $here->id)->where('server_id', $server->id)->exists()) {
            return [...$row, 'state' => 'here'];
        }

        $problem = TargetFolderRules::problem($server, $path);

        return $problem === null ? $row : [...$row, 'state' => 'refused', 'reason' => $problem];
    }

    /**
     * What to know about a project before it's created here.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function notes(array $entry, ProjectSource $source, ProjectRoots $roots): array
    {
        $notes = [];
        $owner = $entry['github_owner'] ?? null;

        if (is_string($owner) && $owner !== '' && Credential::forOwner($owner) === null) {
            $notes[] = "No GitHub token here reaches {$owner}: add one in Settings → GitHub.";
        }

        if ($source === ProjectSource::Local && $roots->resolve((string) ($entry['local_path'] ?? '')) === null) {
            $notes[] = "{$entry['local_path']} isn’t a folder inside this machine’s project roots. Deploys wait until it is.";
        }

        return $notes;
    }

    /**
     * The same project, already here: by slug, then repository, then folder.
     *
     * @param  array<string, mixed>  $entry
     */
    private function existing(array $entry, ProjectRoots $roots): ?Project
    {
        $active = fn () => Project::query()->active();
        $owner = $entry['github_owner'] ?? null;
        $repo = $entry['github_repo'] ?? null;
        $path = is_string($entry['local_path'] ?? null) ? $roots->normalize($entry['local_path']) : null;

        return $active()->where('slug', $entry['slug'])->first()
            ?? (($entry['source'] ?? null) === ProjectSource::Github->value && is_string($owner) && is_string($repo)
                ? $active()->where('source', ProjectSource::Github)->whereRaw('lower(github_owner) = ? and lower(github_repo) = ?', [strtolower($owner), strtolower($repo)])->first()
                : null)
            ?? ($path !== null ? $active()->where('local_path', $path)->first() : null);
    }

    private static function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }
}
