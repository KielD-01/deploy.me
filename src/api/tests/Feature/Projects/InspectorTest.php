<?php

use App\Platform\Detection\Detection;
use App\Platform\Detection\GitHubInspector;
use App\Platform\Detection\LocalInspector;
use App\Platform\GitHub\GitHubClient;
use App\Platform\Ssh\SshConfigReader;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $dir = sys_get_temp_dir().'/deployme-sshcfg-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents("{$dir}/config", "Host gh-work\n  HostName github.com\nHost build-box\n  HostName 198.51.100.23\n");
    $this->app->instance(SshConfigReader::class, new SshConfigReader(["{$dir}/config"], $dir));

    Http::preventStrayRequests();
});

it('reads a local repository’s git state and the GitHub repository behind origin', function () {
    $repo = tempGitRepo([
        'compose.yaml' => "name: demo\nservices:\n  app:\n    build: .\n",
        'README.md' => "# Demo\n",
    ], remote: 'gh-work:KielD-01/demo.git');
    file_put_contents("{$repo}/notes.txt", "not committed\n");

    $detection = new Detection(app(LocalInspector::class)->inspect($repo));

    expect($detection->git())->toMatchArray(['branch' => 'main', 'commits' => 1, 'dirty' => 1, 'upstream' => null])
        ->and($detection->git()['head']['subject'])->toBe('First commit')
        ->and($detection->github())->toBe(['owner' => 'KielD-01', 'repo' => 'demo', 'remote' => 'origin', 'transport' => 'alias', 'alias' => 'gh-work'])
        ->and($detection->remotes())->toBe([['name' => 'origin', 'url' => 'gh-work:KielD-01/demo.git']])
        ->and($detection->suggestedStackName('fallback'))->toBe('demo')
        ->and(array_column($detection->suggestedStrategies(localFolder: true), 'key'))->toBe(['push_archive', 'push_rsync', 'server_git', 'ghcr_image']);
});

it('handles a folder that isn’t a git repository', function () {
    $detection = new Detection(app(LocalInspector::class)->inspect(tempProject(['index.html' => '<h1>Hi</h1>'])));

    expect($detection->git())->toBeNull()
        ->and($detection->github())->toBeNull()
        ->and($detection->suggestedStrategies(localFolder: true)[0])->toBe(['key' => 'push_rsync', 'enabled' => true]);
});

it('inspects a GitHub repository through the API without cloning it', function () {
    fakeGitHubRepository();

    $detection = new Detection(app(GitHubInspector::class)->inspect(new GitHubClient('ghp_test'), 'KielD-01', 'demo'));

    expect($detection->github())->toMatchArray(['owner' => 'KielD-01', 'branch' => 'main', 'commits' => 312, 'private' => true])
        ->and($detection->github()['head']['subject'])->toBe('Add deploy')
        ->and($detection->github()['ci']['state'])->toBe('passing')
        ->and($detection->framework()['name'])->toBe('Laravel')
        ->and($detection->suggestedStackName('demo'))->toBe('example')
        ->and($detection->imagePublishing()['tag'])->toBe('{sha}');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'node_modules'));
});
