<?php

namespace App\Deploy\Delivery;

use Illuminate\Support\Facades\File;

/**
 * A folder in the worker holding the files to upload: an export of one
 * commit (thrown away afterwards), or the project folder itself.
 */
final class Source
{
    public function __construct(
        public readonly string $path,
        // A fresh export: every file carries the commit's time, so compare by content.
        public readonly bool $exported,
        public readonly bool $temporary,
        public readonly ?string $commit = null,
        public readonly ?string $commitMessage = null,
    ) {}

    public function discard(): void
    {
        if ($this->temporary && is_dir($this->path)) {
            File::deleteDirectory($this->path);
        }
    }
}
