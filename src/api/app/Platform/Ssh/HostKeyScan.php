<?php

namespace App\Platform\Ssh;

/**
 * The host key(s) a server offered on first contact, before anyone trusted them.
 */
final readonly class HostKeyScan
{
    /**
     * @param  list<string>  $lines  known_hosts lines exactly as ssh wrote them
     * @param  list<array{type: string, fingerprint: string}>  $keys
     */
    public function __construct(
        public string $alias,
        public array $lines,
        public array $keys,
    ) {}

    /**
     * @return list<string>
     */
    public function fingerprints(): array
    {
        return array_column($this->keys, 'fingerprint');
    }

    /**
     * @return array{alias: string, lines: list<string>, keys: list<array{type: string, fingerprint: string}>}
     */
    public function toArray(): array
    {
        return ['alias' => $this->alias, 'lines' => $this->lines, 'keys' => $this->keys];
    }

    /**
     * @param  array{alias: string, lines: list<string>, keys: list<array{type: string, fingerprint: string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['alias'], $data['lines'], $data['keys']);
    }
}
