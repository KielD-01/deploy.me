<?php

namespace App\Platform\Detection;

/**
 * A project's files, wherever they are: a local folder or a GitHub tree.
 */
interface FileSource
{
    /**
     * Every file down to the scan depth, relative to the project root, with
     * dependency and build folders skipped.
     *
     * @return list<string>
     */
    public function files(): array;

    /**
     * A file's contents; null when it's missing, unreadable or too large.
     */
    public function read(string $path): ?string;

    /**
     * The files about to be read, so a remote source can fetch them together.
     *
     * @param  list<string>  $paths
     */
    public function prefetch(array $paths): void;

    /**
     * Whether the listing stopped early because the tree is huge.
     */
    public function truncated(): bool;
}
