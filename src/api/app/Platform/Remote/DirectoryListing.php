<?php

namespace App\Platform\Remote;

final readonly class DirectoryListing
{
    /**
     * @param  list<RemoteEntry>  $entries  directories first, then natural name order
     * @param  array{source: string, fstype: string, size: int, available: int, mount: string}|null  $filesystem
     */
    public function __construct(
        public string $path,
        public bool $writable,
        public ?array $filesystem,
        public array $entries,
        public bool $truncated,
    ) {}

    /**
     * Parses the output of resources/ssh-scripts/ls.sh.
     */
    public static function parse(string $stdout, int $limit): self
    {
        $path = preg_match('/^__DEPLOYME_CWD__ (.+)$/m', $stdout, $m) ? $m[1] : '/';
        $writable = (bool) preg_match('/^__DEPLOYME_CWD_WRITABLE__ 1$/m', $stdout);
        $filesystem = preg_match('/^__DEPLOYME_DF__ (\S+) (\S+) (\d+) (\d+) (\S+)$/m', $stdout, $d)
            ? ['source' => $d[1], 'fstype' => $d[2], 'size' => (int) $d[3], 'available' => (int) $d[4], 'mount' => $d[5]]
            : null;

        $writableNames = array_flip(array_filter(explode("\0", self::section($stdout, "__DEPLOYME_WRITABLE__\n", null))));

        $entries = [];
        foreach (explode("\0", self::section($stdout, "__DEPLOYME_ENTRIES__\n", "\n__DEPLOYME_WRITABLE__")) as $record) {
            $fields = explode("\t", $record, 8);
            if (count($fields) < 8) {
                continue;
            }

            [$type, $targetType, $mode, $owner, $group, $size, $mtime, $name] = $fields;

            $entries[] = new RemoteEntry(
                name: $name,
                type: match ($type) {
                    'd' => 'dir', 'f' => 'file', 'l' => 'link', default => 'other'
                },
                isDir: $type === 'd' || ($type === 'l' && $targetType === 'd'),
                mode: $mode,
                owner: $owner,
                group: $group,
                size: (int) $size,
                modifiedAt: (int) $mtime,
                writable: isset($writableNames[$name]),
            );
        }

        usort($entries, fn (RemoteEntry $a, RemoteEntry $b) => $a->isDir === $b->isDir
            ? strnatcasecmp($a->name, $b->name)
            : ($a->isDir ? -1 : 1));

        return new self($path, $writable, $filesystem, $entries, count($entries) >= $limit);
    }

    private static function section(string $stdout, string $start, ?string $end): string
    {
        $from = strpos($stdout, $start);
        if ($from === false) {
            return '';
        }

        $from += strlen($start);
        $to = $end === null ? false : strpos($stdout, $end, $from);

        return $to === false ? substr($stdout, $from) : substr($stdout, $from, $to - $from);
    }
}
