<?php

namespace App\Platform\Sync;

/**
 * Reads what rsync prints with `--itemize-changes --out-format='%i %l %n%L'
 * --stats --no-human-readable`: one line per change, then the totals.
 */
final class RsyncOutput
{
    // Enough for the preview; the counts cover the rest.
    public const KEEP = 500;

    /** @var list<array{kind: string, path: string, size: int}> */
    public array $changes = [];

    /** @var array{new: int, updated: int, deleted: int, folders: int, links: int} */
    public array $counts = ['new' => 0, 'updated' => 0, 'deleted' => 0, 'folders' => 0, 'links' => 0];

    /** @var array<string, int> */
    private array $totals = [];

    public function line(string $line): void
    {
        $line = rtrim($line);

        if (preg_match('/^\*deleting\s+(?:\d+\s+)?(.+)$/', $line, $match)) {
            $this->change('deleted', $match[1], 0);

            return;
        }

        // YXcstpoguax: Y what happens (< sent, > received, c created, . nothing
        // sent), X the type (f file, d folder, L link), then attributes; all
        // "+" for something new.
        if (preg_match('/^([<>ch.*][fdLDS]\S{9}) (\d+) (.+)$/', $line, $match)) {
            [, $code, $size, $name] = $match;
            $new = str_contains(substr($code, 2), '+');

            // The destination folder itself, when it doesn't exist yet.
            if ($name === './') {
                return;
            }

            match (true) {
                $code[1] === 'd' => $new ? $this->change('folder', rtrim($name, '/'), 0) : null,
                $code[1] === 'L' => $this->change('link', $name, 0),
                $code[1] !== 'f', $code[0] === '.' => null,
                default => $this->change($new ? 'new' : 'updated', $name, (int) $size),
            };

            return;
        }

        if (preg_match('/^([A-Z][a-z][A-Za-z ]+):\s+(\d+)/', $line, $match)) {
            $this->totals[strtolower($match[1])] = (int) $match[2];
        }
    }

    /**
     * Files and links added, changed or deleted; new folders aren't counted.
     */
    public function changeCount(): int
    {
        return $this->counts['new'] + $this->counts['updated'] + $this->counts['deleted'] + $this->counts['links'];
    }

    /**
     * @return array{files: ?int, transferred: int, transferred_bytes: int, total_bytes: ?int, deleted: int, counts: array<string, int>}
     */
    public function stats(): array
    {
        $sent = array_sum(array_map(fn (array $change) => $change['size'], array_filter($this->changes, fn (array $change) => in_array($change['kind'], ['new', 'updated'], true))));

        return [
            'files' => $this->totals['number of files'] ?? null,
            'transferred' => $this->totals['number of regular files transferred'] ?? $this->counts['new'] + $this->counts['updated'],
            'transferred_bytes' => $this->totals['total transferred file size'] ?? $sent,
            'total_bytes' => $this->totals['total file size'] ?? null,
            'deleted' => $this->totals['number of deleted files'] ?? $this->counts['deleted'],
            'counts' => $this->counts,
        ];
    }

    private function change(string $kind, string $path, int $size): void
    {
        $this->counts[match ($kind) {
            'folder' => 'folders',
            'link' => 'links',
            default => $kind,
        }]++;

        if (count($this->changes) < self::KEEP) {
            $this->changes[] = ['kind' => $kind, 'path' => $path, 'size' => $size];
        }
    }
}
