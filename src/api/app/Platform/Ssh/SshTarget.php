<?php

namespace App\Platform\Ssh;

/**
 * The effective connection settings for an alias, as `ssh -G` resolves them.
 */
final readonly class SshTarget
{
    /**
     * @param  list<string>  $identityFiles
     */
    public function __construct(
        public string $alias,
        public string $hostName,
        public string $user,
        public int $port,
        public array $identityFiles,
        public ?string $proxyJump,
        public ?string $hostKeyAlias,
        public bool $identitiesOnly,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
