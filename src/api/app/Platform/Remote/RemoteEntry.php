<?php

namespace App\Platform\Remote;

final readonly class RemoteEntry
{
    public function __construct(
        public string $name,
        public string $type, // dir | file | link | other
        public bool $isDir, // a directory, or a link to one
        public string $mode,
        public string $owner,
        public string $group,
        public int $size,
        public int $modifiedAt,
        public bool $writable,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
