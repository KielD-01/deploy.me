<?php

use App\Platform\Git\GitRemote;

it('reads the GitHub repository behind a remote URL', function (string $url, ?array $expected) {
    $remote = GitRemote::parse($url, fn (string $host) => $host === 'gh-work');

    expect($remote === null ? null : [$remote->owner, $remote->repo, $remote->transport, $remote->alias])->toBe($expected);
})->with([
    'https' => ['https://github.com/KielD-01/example.dev.git', ['KielD-01', 'example.dev', 'https', null]],
    'https without .git' => ['https://github.com/Acme-Corp/shop-prod', ['Acme-Corp', 'shop-prod', 'https', null]],
    'https with a token in it' => ['https://x-access-token:ghp_secret@github.com/KielD-01/app.git', ['KielD-01', 'app', 'https', null]],
    'scp-like ssh' => ['git@github.com:KielD-01/app.git', ['KielD-01', 'app', 'ssh', null]],
    'ssh url' => ['ssh://git@github.com/KielD-01/app.git', ['KielD-01', 'app', 'ssh', null]],
    'ssh config alias' => ['gh-work:MR-Studio-KZ-UA/mr-erp.git', ['MR-Studio-KZ-UA', 'mr-erp', 'alias', 'gh-work']],
    'another git host' => ['git@gitlab.com:group/app.git', null],
    'an alias that isn’t GitHub' => ['work-box:owner/app.git', null],
]);

it('strips credentials from http URLs anywhere in a text', function () {
    expect(GitRemote::redact('fatal: unable to access https://user:ghp_abc@github.com/o/r.git/'))
        ->toBe('fatal: unable to access https://github.com/o/r.git/');
});
