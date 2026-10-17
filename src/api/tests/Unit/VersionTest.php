<?php

use App\Support\Version;

it('reads a release number the way the tags carry it', function (string $text, ?array $parsed) {
    expect(Version::parse($text))->toBe($parsed);
})->with([
    ['v1.2.3', [1, 2, 3]],
    ['1.2.3', [1, 2, 3]],
    ['1.2', [1, 2, 0]],
    [' v10.0.1 ', [10, 0, 1]],
    ['v1.2.3-rc1', null],
    ['latest', null],
    ['', null],
    ['1.2.3; rm -rf /', null],
]);

it('says how far a release is from this one, or nothing when it is not newer', function (string $current, string $latest, ?string $kind) {
    expect(Version::kind($current, $latest))->toBe($kind);
})->with([
    ['1.0.1', '1.0.2', 'patch'],
    ['1.0.1', 'v1.1.0', 'minor'],
    ['1.9.9', '2.0.0', 'major'],
    ['1.0.2', '1.0.2', null],
    ['1.0.2', '1.0.1', null],
    ['1.10.0', '1.9.0', null],
    ['dev', '1.0.0', null],
    ['1.0.0', 'banana', null],
]);

it('writes a version the same way whatever it came as', function () {
    expect(Version::normalize('v1.2'))->toBe('1.2.0')
        ->and(Version::normalize('1.2.3'))->toBe('1.2.3')
        ->and(Version::normalize('nope'))->toBe('');
});
