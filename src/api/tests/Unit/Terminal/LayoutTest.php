<?php

use App\Terminal\Layout;

it('starts from the ops layout: a shell beside the run, htop and git', function () {
    $kinds = array_column(Layout::leaves(Layout::preset('ops')), 'kind');

    expect($kinds)->toBe(['shell', 'tail', 'htop', 'git']);
});

it('splits a pane right or down, with a new id', function () {
    $tree = Layout::split(Layout::preset('deploy'), 'p1', 'col', 'htop', 'prod-eu');

    expect(Layout::leaves($tree))->toBe([
        ['id' => 'p0', 'kind' => 'shell', 'arg' => null],
        ['id' => 'p1', 'kind' => 'tail', 'arg' => null],
        ['id' => 'p2', 'kind' => 'htop', 'arg' => 'prod-eu'],
    ])->and($tree['children'][1]['dir'])->toBe('col');
});

it('closes a pane and lets its sibling take its place, but never the last one', function () {
    $tree = Layout::close(Layout::preset('deploy'), 'p1');

    expect($tree)->toBe(['id' => 'p0', 'kind' => 'shell', 'arg' => null])
        ->and(Layout::close($tree, 'p0'))->toBe($tree);
});

it('resizes a split and keeps the sizes in percent', function () {
    $tree = Layout::resize(Layout::preset('ops'), [1], [20, 20, 40]);

    expect($tree['children'][1]['sizes'])->toBe([25.0, 25.0, 50.0]);
});

it('makes a stored tree safe: unknown kinds, odd ids and args, empty splits', function () {
    $tree = Layout::normalize([
        'dir' => 'diagonal',
        'sizes' => [3, 'x'],
        'children' => [
            ['id' => 'p7', 'kind' => 'rm -rf', 'arg' => '<script>'],
            ['children' => []],
            ['id' => 'nope', 'kind' => 'git', 'arg' => 'warehouse'],
        ],
    ]);

    expect($tree)->toBe(['dir' => 'row', 'sizes' => [50.0, 50.0], 'children' => [
        ['id' => 'p7', 'kind' => 'shell', 'arg' => null],
        ['id' => 'p0', 'kind' => 'git', 'arg' => 'warehouse'],
    ]])->and(Layout::normalize('nonsense'))->toBeNull();
});
