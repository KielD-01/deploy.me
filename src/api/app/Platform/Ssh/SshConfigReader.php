<?php

namespace App\Platform\Ssh;

/**
 * Lists the literal Host aliases in the user's ssh config (and extra configs),
 * following Include the way ssh does. Wildcard, negated and Match entries are
 * skipped: they aren't servers you can pick.
 */
final class SshConfigReader
{
    private const MAX_INCLUDE_DEPTH = 16;

    /**
     * @param  list<string>  $files
     */
    public function __construct(
        private readonly array $files,
        private readonly string $home,
    ) {}

    public static function fromConfig(): self
    {
        $source = (string) config('deployme.ssh.source_config');

        return new self(
            array_values(array_filter([$source, ...config('deployme.ssh.extra_configs', [])], fn ($file) => is_string($file) && is_readable($file))),
            dirname($source, 2),
        );
    }

    /**
     * @return list<SshAlias>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach ($this->files as $file) {
            foreach ($this->parse($file, 0) as $name => $alias) {
                $aliases[$name] ??= $alias; // the first definition wins, as in ssh
            }
        }

        return array_values($aliases);
    }

    public function find(string $name): ?SshAlias
    {
        foreach ($this->aliases() as $alias) {
            if ($alias->name === $name) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * @return array<string, SshAlias>
     */
    private function parse(string $file, int $depth): array
    {
        if ($depth > self::MAX_INCLUDE_DEPTH || ! is_readable($file)) {
            return [];
        }

        $found = [];
        $block = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#') || ! preg_match('/^(\S+?)(?:\s*=\s*|\s+)(.+)$/', $line, $m)) {
                continue;
            }

            [$keyword, $value] = [strtolower($m[1]), trim($m[2])];

            if ($keyword === 'host') {
                $block = [];
                foreach ($this->words($value) as $pattern) {
                    if (strpbrk($pattern, '*?!') !== false) {
                        continue;
                    }
                    $block[] = $pattern;
                    $found[$pattern] ??= ['file' => $file, 'line' => $index + 1, 'hostName' => null];
                }
            } elseif ($keyword === 'match') {
                $block = [];
            } elseif ($keyword === 'hostname') {
                foreach ($block as $name) {
                    $found[$name]['hostName'] ??= $this->words($value)[0] ?? null;
                }
            } elseif ($keyword === 'include') {
                foreach ($this->words($value) as $pattern) {
                    foreach (glob($this->expand($pattern, dirname($file))) ?: [] as $included) {
                        foreach ($this->parse($included, $depth + 1) as $name => $alias) {
                            $found[$name] ??= ['file' => $alias->sourceFile, 'line' => $alias->line, 'hostName' => $alias->hostName];
                        }
                    }
                }
            }
        }

        $aliases = [];
        foreach ($found as $name => $data) {
            $aliases[$name] = new SshAlias($name, $data['hostName'], $data['file'], $data['line']);
        }

        return $aliases;
    }

    /**
     * Include paths are relative to ~/.ssh unless absolute; `~` is the user's home.
     */
    private function expand(string $pattern, string $dir): string
    {
        if (str_starts_with($pattern, '~/')) {
            return $this->home.substr($pattern, 1);
        }

        return str_starts_with($pattern, '/') ? $pattern : $dir.'/'.$pattern;
    }

    /**
     * @return list<string>
     */
    private function words(string $value): array
    {
        return array_values(array_filter(str_getcsv($value, ' ', '"', '\\'), fn ($word) => $word !== '' && $word !== null));
    }
}
