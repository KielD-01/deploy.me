<?php

use App\Deploy\Delivery\GitHubMirror;
use App\Deploy\Pipeline\StepFailed;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // A storage folder of its own, so the mirrors a test makes go with it.
    $this->storage = sys_get_temp_dir().'/deployme-mirrors-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->storage.'/app');
    $this->app->useStoragePath($this->storage);
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

it('won’t fetch a repository no token reaches', function () {
    Process::fake();

    expect(fn () => app(GitHubMirror::class)->sync(Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo'])))
        ->toThrow(StepFailed::class, 'No GitHub token reaches KielD-01. Add one in Settings.');

    Process::assertNothingRan();
});

it('clones the mirror once, then fetches it, with the token only in the environment', function () {
    Process::fake();
    $credential = Credential::factory()->create(['login' => 'KielD-01']);
    $project = Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'credential_id' => $credential->id]);
    $mirror = app(GitHubMirror::class);
    $path = $mirror->path($project);

    expect($mirror->sync($project))->toBe($path)
        ->and($path)->toEndWith("/app/mirrors/{$project->id}.git");

    Process::assertRan(fn ($process) => $process->command === [
        'git', 'clone', '--mirror', '--quiet', 'https://github.com/KielD-01/demo.git', $path,
    ]);

    // The token never reaches git's command line — only GIT_ASKPASS reads it.
    Process::assertRan(fn ($process) => ($process->environment['DEPLOYME_GIT_TOKEN'] ?? null) === $credential->token
        && ($process->environment['DEPLOYME_GIT_USERNAME'] ?? null) === 'KielD-01'
        && ! str_contains(implode(' ', $process->command), $credential->token));

    // With the mirror there, the next sync brings it up to date instead.
    File::ensureDirectoryExists($path);
    Process::fake();
    $mirror->sync($project);

    Process::assertRan(fn ($process) => $process->command === ['git', 'remote', 'set-url', 'origin', 'https://github.com/KielD-01/demo.git']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'fetch', '--prune', '--quiet', 'origin']);
});

it('says what git said when the fetch fails', function () {
    Process::fake([
        '*fetch*' => Process::result(errorOutput: "fatal: could not read Username\n", exitCode: 128),
        '*' => Process::result(),
    ]);
    $credential = Credential::factory()->create(['login' => 'KielD-01']);
    $project = Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'credential_id' => $credential->id]);
    File::ensureDirectoryExists(app(GitHubMirror::class)->path($project));

    expect(fn () => app(GitHubMirror::class)->sync($project))
        ->toThrow(StepFailed::class, 'Couldn’t fetch KielD-01/demo from GitHub: fatal: could not read Username');
});
