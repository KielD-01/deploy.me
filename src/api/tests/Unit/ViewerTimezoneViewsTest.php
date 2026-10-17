<?php

// Pages print times in the viewer's timezone (App\Support\ViewerTimezone): a
// timestamp reaches a view through `->forViewer()`, and only then is formatted.
// A bare `$run->created_at->format('H:i')` prints UTC (or the app's zone) to
// someone whose browser says otherwise, and no test with one timezone notices.
// This reads the Blade views and fails on a date method whose receiver doesn't
// go through forViewer(), either on the same expression or in the `$variable =`
// it was assigned from.

const TIME_METHODS = 'format|isToday|isYesterday|isTomorrow|toFormattedDateString|toFormattedDayDateString|toDateString|toTimeString|toDayDateTimeString|toDateTimeString|isoFormat|translatedFormat';

/**
 * Where a call's receiver starts: back over names, `->`, `?`, brackets and
 * balanced parentheses, and over a line break in a chain written on several lines.
 */
function receiverOf(string $source, int $callAt): string
{
    $depth = 0;
    $start = $callAt;

    for ($i = $callAt - 1; $i >= 0; $i--) {
        $char = $source[$i];

        if ($char === ')') {
            $depth++;
        } elseif ($char === '(') {
            if ($depth === 0) {
                break;
            }
            $depth--;
        } elseif ($depth === 0 && ctype_space($char)) {
            $j = $i;
            while ($j >= 0 && ctype_space($source[$j])) {
                $j--;
            }
            $broke = str_contains(substr($source, $j + 1, $i - $j), "\n");

            if (! $broke || $j < 0 || ! preg_match('/[\w)\]]/', $source[$j])) {
                break;
            }
            $i = $j + 1;

            continue;
        } elseif ($depth === 0 && ! preg_match('/[\w$>\-?\[\]\'"]/', $char)) {
            break;
        }

        $start = $i;
    }

    return rtrim((string) preg_replace('/\s+/', '', substr($source, $start, $callAt - $start)), '?');
}

/**
 * @return list<string> "<view> <receiver>-><method>()" for each call that skips forViewer()
 */
function timesWithoutForViewer(): array
{
    $root = dirname(__DIR__, 2).'/resources/views';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $view = ltrim(substr($file->getPathname(), strlen($root)), '/');
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('/->('.TIME_METHODS.')\(/', $source, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[1] as [$method, $offset]) {
            $receiver = receiverOf($source, $offset - 2);

            if (str_contains($receiver, 'forViewer()')) {
                continue;
            }

            if (preg_match('/^\$(\w+)$/', $receiver, $variable) === 1
                && preg_match('/\$'.$variable[1].'\s*=[^;]*forViewer\(\)/', $source) === 1) {
                continue;
            }

            $found[] = "{$view} {$receiver}->{$method}()";
        }
    }

    sort($found);

    return array_values(array_unique($found));
}

// Views that still print a time without forViewer(), left for whoever next edits
// them (the server page and the Console theme's copies were being rewritten when
// this guard was added). All of them are tooltips or dates.
// Fix one and remove its line here: the second test fails on a stale entry.
const TIMES_LEFT_IN_THE_SERVER_ZONE = [
    'livewire/servers/show.blade.php $check->checked_at->toDayDateTimeString()',
    'livewire/servers/show.blade.php $hostKey?->trusted_at->toFormattedDateString()',
    'themes/console/livewire/servers/show.blade.php $check->checked_at->toDayDateTimeString()',
    'themes/console/livewire/servers/show.blade.php $hostKey?->trusted_at->toFormattedDateString()',
    'themes/console/partials/trail.blade.php $entry->occurred_at->toDayDateTimeString()',
];

it('formats every time a view prints through forViewer()', function () {
    expect(array_values(array_diff(timesWithoutForViewer(), TIMES_LEFT_IN_THE_SERVER_ZONE)))
        ->toBe([], 'These print a time in the server’s timezone. Call ->forViewer() before formatting it.');
});

it('keeps no entry in the allowlist for a call that has been fixed', function () {
    expect(array_values(array_diff(TIMES_LEFT_IN_THE_SERVER_ZONE, timesWithoutForViewer())))
        ->toBe([], 'These are fixed (or gone): remove them from TIMES_LEFT_IN_THE_SERVER_ZONE.');
});
