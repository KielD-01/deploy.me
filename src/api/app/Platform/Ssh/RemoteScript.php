<?php

namespace App\Platform\Ssh;

use InvalidArgumentException;

/**
 * A script sent to the server over stdin (`ssh <alias> bash -s`), so no user
 * data is ever part of the ssh command line. Variables are injected base64
 * encoded, so any value (quotes, newlines, `$`) arrives unchanged and unexpanded.
 *
 * A deploy script runs in a session of its own (util-linux `setsid`, where
 * the server has it) and, given PGID_FILE, writes its process group there
 * while it runs: what it starts can then be stopped with it (deploy-kill.sh).
 */
final readonly class RemoteScript
{
    // PGID_FILE's folder has to exist; on a target that has none yet, nothing is written.
    private const PGID_PRELUDE = <<<'SH'
        if [ -n "${PGID_FILE:-}" ] && [ -d "${PGID_FILE%/*}" ]; then printf '%s\n' "$$" > "$PGID_FILE" 2>/dev/null && trap 'rm -f -- "$PGID_FILE"' EXIT; fi
        SH;

    /**
     * @param  array<string, string|int>  $vars
     */
    private function __construct(
        public string $name,
        private string $body,
        private array $vars,
        public string $shell,
        public bool $ownSession = false,
    ) {
        foreach (array_keys($vars) as $key) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                throw new InvalidArgumentException("Invalid script variable [{$key}].");
            }
        }

        if (! in_array($shell, ['bash', 'sh'], true)) {
            throw new InvalidArgumentException("Unsupported shell [{$shell}].");
        }
    }

    /**
     * A script from resources/ssh-scripts/{name}.sh.
     *
     * @param  array<string, string|int>  $vars
     */
    public static function file(string $name, array $vars = [], string $shell = 'bash'): self
    {
        $path = resource_path("ssh-scripts/{$name}.sh");

        if (! preg_match('/^[a-z0-9-]+$/', $name) || ! is_file($path)) {
            throw new InvalidArgumentException("Unknown remote script [{$name}].");
        }

        return new self($name, (string) file_get_contents($path), $vars, $shell);
    }

    /**
     * @param  array<string, string|int>  $vars
     */
    public static function inline(string $name, string $body, array $vars = [], string $shell = 'bash'): self
    {
        return new self($name, $body, $vars, $shell);
    }

    /**
     * The same script, run as the leader of a session of its own, so its
     * whole process group can be stopped from outside.
     */
    public function inOwnSession(): self
    {
        return new self($this->name, $this->body, $this->vars, $this->shell, ownSession: true);
    }

    /**
     * What ssh asks the server to run; the script arrives on its stdin. In a
     * session of its own, `setsid --wait` makes it the leader and keeps its
     * exit code; a server without setsid, or with one that can't wait
     * (busybox), runs it the plain way.
     */
    public function command(): string
    {
        return $this->ownSession
            ? "exec sh -c 'if setsid --wait true 2>/dev/null; then exec setsid --wait {$this->shell} -s; fi; exec {$this->shell} -s'"
            : "{$this->shell} -s";
    }

    public function render(): string
    {
        $lines = ['set -u'];

        foreach ($this->vars as $key => $value) {
            // The trailing x survives command substitution, which would drop
            // trailing newlines, and is then cut off again.
            $lines[] = sprintf("%s=$(printf %%s '%s' | base64 -d; printf x); %s=\${%s%%x}", $key, base64_encode((string) $value), $key, $key);
        }

        // A deploy script notes its process group while it runs.
        if (array_key_exists('PGID_FILE', $this->vars)) {
            $lines[] = self::PGID_PRELUDE;
        }

        return implode("\n", $lines)."\n".rtrim($this->body)."\n";
    }

    public function digest(): string
    {
        return hash('sha256', $this->render());
    }
}
