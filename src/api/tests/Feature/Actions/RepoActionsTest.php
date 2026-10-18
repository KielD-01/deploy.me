<?php

use App\Deploy\Actions\RepoActions;
use App\Livewire\Projects\Actions;
use App\Models\Credential;
use App\Models\ProjectAction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const CLEAR_CACHES = <<<'YAML'
    name: Clear caches
    steps:
      - { name: Optimize clear, exec: api, run: php artisan optimize:clear }
    YAML;

beforeEach(function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
});

it('reads a local repository’s actions at the branch head, not the working tree', function () {
    $repo = tempGitRepo([
        '.deployme/actions/clear-caches.yml' => CLEAR_CACHES,
        '.deployme/actions/broken.yaml' => "name: Broken\nsteps: nope\n",
        '.deployme/actions/README.md' => 'not an action',
    ]);
    // Uncommitted: not what the branch deploys.
    file_put_contents("{$repo}/.deployme/actions/draft.yml", CLEAR_CACHES);
    $target = demoTarget(['source' => 'local', 'local_path' => $repo]);

    $result = app(RepoActions::class)->reload($target->project);
    $actions = $target->project->actions()->get()->keyBy('key');

    expect($result)->toMatchArray(['actions' => 2, 'errors' => 1])
        ->and($result['commit'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($actions->keys()->all())->toBe(['broken', 'clear-caches'])
        ->and($actions['clear-caches']->runnable())->toBeTrue()
        ->and($actions['clear-caches']->file)->toBe('.deployme/actions/clear-caches.yml')
        ->and($actions['clear-caches']->commit_sha)->toBe($result['commit'])
        ->and($actions['broken']->runnable())->toBeFalse()
        ->and(implode(' ', $actions['broken']->errors))->toContain('steps: a list of at least one step');
});

it('drops a repo action whose file is gone, and never touches one made here', function () {
    $repo = tempGitRepo(['.deployme/actions/clear-caches.yml' => CLEAR_CACHES]);
    $target = demoTarget(['source' => 'local', 'local_path' => $repo]);
    $repoActions = app(RepoActions::class);
    $repoActions->reload($target->project);
    projectAction($target->project, "name: Mine\nsteps: [ls]\n", 'mine');

    unlink("{$repo}/.deployme/actions/clear-caches.yml");
    commitFile($repo, 'README.md', 'gone');

    expect($repoActions->reload($target->project))->toMatchArray(['actions' => 0, 'removed' => 1])
        ->and($target->project->actions()->pluck('key')->all())->toBe(['mine']);
});

it('says when a repo file’s name is taken by an action made here', function () {
    $repo = tempGitRepo(['.deployme/actions/mine.yml' => CLEAR_CACHES]);
    $target = demoTarget(['source' => 'local', 'local_path' => $repo]);
    projectAction($target->project, "name: Mine\nsteps: [ls]\n", 'mine');

    app(RepoActions::class)->reload($target->project);
    $clash = $target->project->actions()->where('key', 'mine-repo')->first();

    expect($clash->errors[0])->toContain('An action made here is called mine already');
});

it('checks a repo action against the project’s servers', function () {
    $repo = tempGitRepo(['.deployme/actions/nightly.yml' => "name: Nightly\non: { schedule: \"0 3 * * *\", target: elsewhere }\nsteps: [ls]\n"]);
    $target = demoTarget(['source' => 'local', 'local_path' => $repo]);

    app(RepoActions::class)->reload($target->project);

    expect($target->project->actions()->first()->errors[0])->toContain('on.target: the schedule runs on “elsewhere”');
});

it('reads a GitHub repository’s actions through the API at the branch head', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc1234def0000000000000000000000000000ff']]),
        'api.github.com/repos/KielD-01/demo/git/trees/*' => Http::response(['truncated' => false, 'tree' => [
            ['path' => '.deployme/actions/clear-caches.yml', 'type' => 'blob'],
            ['path' => 'src/api/artisan', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/KielD-01/demo/contents/.deployme/actions/clear-caches.yml*' => Http::response(CLEAR_CACHES),
    ]);
    Credential::factory()->create(['login' => 'KielD-01']);
    $target = demoTarget(['source' => 'github', 'github_owner' => 'KielD-01', 'github_repo' => 'demo']);

    $result = app(RepoActions::class)->reload($target->project);

    expect($result)->toMatchArray(['actions' => 1, 'errors' => 0, 'commit' => 'abc1234def0000000000000000000000000000ff'])
        ->and($target->project->actions()->first()->name)->toBe('Clear caches');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'contents/.deployme/actions/clear-caches.yml') && str_contains($request->url(), 'ref=abc1234def'));
});

it('stores the actions a GitHub import read with the rest', function () {
    $target = demoTarget(['source' => 'github', 'github_owner' => 'KielD-01', 'github_repo' => 'demo']);

    app(RepoActions::class)->afterDetection($target->project, [
        'github' => ['head' => ['sha' => str_repeat('c', 40)]],
        'action_files' => ['.deployme/actions/clear-caches.yml' => CLEAR_CACHES],
    ]);

    expect($target->project->actions()->first())->key->toBe('clear-caches')->commit_sha->toBe(str_repeat('c', 40));
});

it('reloads from the Actions tab and says what it read', function () {
    $this->actingAs(User::factory()->create());
    $repo = tempGitRepo(['.deployme/actions/clear-caches.yml' => CLEAR_CACHES]);
    $target = demoTarget(['source' => 'local', 'local_path' => $repo]);

    Livewire::test(Actions::class, ['projectId' => $target->project_id])
        ->call('reload')
        ->assertSet('notice', fn (string $notice) => str_starts_with($notice, 'Read 1 action from .deployme/actions at '))
        ->assertSee('Clear caches')
        ->assertSee('.deployme/actions/clear-caches.yml');

    expect(ProjectAction::query()->count())->toBe(1);
});
