<?php

use App\Platform\Sync\RsyncOutput;

it('reads itemized changes and the totals', function () {
    $output = new RsyncOutput;

    foreach ([
        'sending incremental file list',
        'cd+++++++++ 4096 ./',
        'cd+++++++++ 4096 avatars/',
        '<f+++++++++ 1200 avatars/1049.jpg',
        '<f.st...... 2100 exports/orders.csv',
        '.d..t...... 4096 exports/',
        '.f....og... 10 untouched.txt',
        'cL+++++++++ 0 latest -> exports/orders.csv',
        '*deleting   tmp/old.zip',
        '',
        'Number of files: 9 (reg: 6, dir: 3)',
        'Number of deleted files: 1',
        'Number of regular files transferred: 2',
        'Total file size: 5000 bytes',
        'Total transferred file size: 3300 bytes',
        'sent 3,456 bytes  received 123 bytes',
    ] as $line) {
        $output->line($line);
    }

    expect($output->counts)->toBe(['new' => 1, 'updated' => 1, 'deleted' => 1, 'folders' => 1, 'links' => 1])
        ->and($output->changeCount())->toBe(4)
        ->and(array_column($output->changes, 'kind'))->toBe(['folder', 'new', 'updated', 'link', 'deleted'])
        ->and($output->changes[1])->toBe(['kind' => 'new', 'path' => 'avatars/1049.jpg', 'size' => 1200])
        ->and($output->changes[0]['path'])->toBe('avatars')
        ->and($output->stats())->toMatchArray(['files' => 9, 'transferred' => 2, 'transferred_bytes' => 3300, 'total_bytes' => 5000, 'deleted' => 1]);
});

it('counts everything but keeps only the first changes', function () {
    $output = new RsyncOutput;

    for ($i = 0; $i < RsyncOutput::KEEP + 20; $i++) {
        $output->line(">f+++++++++ 10 file-{$i}.txt");
    }

    expect($output->counts['new'])->toBe(RsyncOutput::KEEP + 20)
        ->and($output->changes)->toHaveCount(RsyncOutput::KEEP)
        ->and($output->stats()['transferred'])->toBe(RsyncOutput::KEEP + 20);
});
