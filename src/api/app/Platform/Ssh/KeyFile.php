<?php

namespace App\Platform\Ssh;

use App\Support\Format;
use InvalidArgumentException;

/**
 * The private key a server entered by hand logs in with, as `ssh -i` takes
 * it, kept as a path inside ~/.ssh.
 *
 * The containers see the host's ~/.ssh read-only (at /root/.ssh, where `~` in
 * an ssh config resolves, and at the same absolute path) and nothing else of
 * the home folder. So ~/.ssh/kz_deploy, $HOME/.ssh/kz_deploy and
 * /Users/me/.ssh/kz_deploy are all the same key, and a key anywhere else
 * can't be reached: parse() says so, rather than ssh failing at the first
 * probe.
 *
 * Only the path is ever stored. problem() looks at the file's type, size,
 * permissions and first line, and never reads the key any further.
 */
final readonly class KeyFile
{
    /** A private key is a few KB; a file this big is something else. */
    private const MAX_BYTES = 64 * 1024;

    /** The first line of every private key format OpenSSH reads. */
    private const HEADER = '/^-----BEGIN (?:OPENSSH |RSA |DSA |EC |ENCRYPTED )?PRIVATE KEY-----$/';

    /**
     * @param  string  $name  the path inside ~/.ssh, like "kz_deploy" or "keys/kz"
     */
    private function __construct(public string $name) {}

    /**
     * What was typed in the form: ~/.ssh/key, $HOME/.ssh/key, the full path on
     * this machine, or `-i ~/.ssh/key` pasted from an ssh command.
     *
     * @throws InvalidArgumentException saying what to type instead
     */
    public static function parse(string $typed, ?string $hostHome = null, ?string $sshDir = null): self
    {
        $path = trim($typed);

        if (preg_match('/^-i\s*(\S.*)$/s', $path, $flag) === 1) {
            $path = trim($flag[1]);
        }

        if (strlen($path) >= 2 && in_array($path[0], ['"', "'"], true) && $path[-1] === $path[0]) {
            $path = substr($path, 1, -1);
        }

        if ($path === '') {
            throw new InvalidArgumentException('Type the path to the key, like ~/.ssh/id_ed25519.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || strlen($path) > 255) {
            throw new InvalidArgumentException('Type the path to the key on one line, like ~/.ssh/id_ed25519.');
        }

        $hostHome = rtrim($hostHome ?? (string) config('deployme.host_home'), '/');
        $containerHome = dirname(rtrim($sshDir ?? self::sshDir(), '/'));

        $inHome = match (true) {
            str_starts_with($path, '~/') => substr($path, 2),
            str_starts_with($path, '$HOME/') => substr($path, 6),
            str_starts_with($path, '${HOME}/') => substr($path, 8),
            $hostHome !== '' && str_starts_with($path, $hostHome.'/') => substr($path, strlen($hostHome) + 1),
            str_starts_with($path, $containerHome.'/') => substr($path, strlen($containerHome) + 1),
            default => null,
        };

        if ($inHome === null) {
            throw new InvalidArgumentException(str_starts_with($path, '/') || str_starts_with($path, '~')
                ? self::outside($path)
                : 'Type the full path, like ~/.ssh/'.basename($path).'.');
        }

        $parts = [];
        foreach (explode('/', $inHome) as $part) {
            if ($part === '..') {
                throw new InvalidArgumentException('Type the path without “..”, like ~/.ssh/id_ed25519.');
            }
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        if ($parts === ['.ssh']) {
            throw new InvalidArgumentException('Type the key file’s name too, like ~/.ssh/id_ed25519.');
        }

        if (count($parts) < 2 || $parts[0] !== '.ssh') {
            throw new InvalidArgumentException(self::outside($path));
        }

        $name = implode('/', array_slice($parts, 1));

        // What goes into the compiled ssh config: no quotes, %-tokens or ${…}.
        if (preg_match('#^[A-Za-z0-9._@+=, -]+(?:/[A-Za-z0-9._@+=, -]+)*$#', $name) !== 1) {
            throw new InvalidArgumentException('Use a key file whose name has only letters, digits, spaces and . _ - @ + = , in it.');
        }

        return new self($name);
    }

    /**
     * A path as stored (~/.ssh/<name>), or null when it no longer passes.
     */
    public static function fromStored(?string $stored): ?self
    {
        if ($stored === null || ! str_starts_with($stored, '~/.ssh/')) {
            return null;
        }

        try {
            return self::parse($stored);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Where the containers keep the host's ~/.ssh: beside the ssh config.
     */
    public static function sshDir(): string
    {
        return dirname((string) config('deployme.ssh.source_config'));
    }

    /**
     * How it's shown and stored: ~/.ssh/kz_deploy.
     */
    public function display(): string
    {
        return '~/.ssh/'.$this->name;
    }

    /**
     * Where ssh in the containers opens it: /root/.ssh/kz_deploy.
     */
    public function path(?string $sshDir = null): string
    {
        return rtrim($sshDir ?? self::sshDir(), '/').'/'.$this->name;
    }

    /**
     * Why ssh couldn't use the file, or null when it can. Reads nothing but the
     * file's first line.
     */
    public function problem(?string $sshDir = null): ?string
    {
        $path = $this->path($sshDir);
        $shown = $this->display();
        clearstatcache(true, $path);

        if (! file_exists($path)) {
            return "There’s no file at {$shown}. See what’s there with: ls ~/.ssh";
        }

        if (is_dir($path)) {
            return "{$shown} is a folder. Point to the key file inside it.";
        }

        if (! is_file($path)) {
            return "{$shown} isn’t a regular file.";
        }

        if (! is_readable($path)) {
            return "deploy.me can’t read {$shown}. It has to belong to you: ls -l {$shown}";
        }

        $size = (int) filesize($path);

        if ($size === 0) {
            return "{$shown} is empty.";
        }

        if ($size > self::MAX_BYTES) {
            return "{$shown} is ".Format::bytes($size).': too big to be a private key.';
        }

        $first = self::firstLine($path);

        if (str_starts_with($first, 'PuTTY-User-Key-File-')) {
            return "{$shown} is a PuTTY key, which ssh can’t read. Convert it: puttygen {$shown} -O private-openssh -o ~/.ssh/".preg_replace('/\.ppk$/i', '', $this->name);
        }

        if (preg_match('/^(?:ssh-|ecdsa-|sk-)\S+ AAAA/', $first) === 1) {
            return str_ends_with($this->name, '.pub')
                ? "{$shown} is the public key. Use the private one: ~/.ssh/".substr($this->name, 0, -4)
                : "{$shown} is a public key. Use the private key it belongs to.";
        }

        if (preg_match(self::HEADER, $first) !== 1) {
            return "{$shown} isn’t a private key: its first line should read -----BEGIN OPENSSH PRIVATE KEY----- (or another BEGIN … PRIVATE KEY).";
        }

        $mode = fileperms($path) & 0777;

        if (($mode & 0077) !== 0) {
            return sprintf('ssh refuses a private key others can read, and %s is %o. Run on this machine: chmod 600 %s', $shown, $mode, $shown);
        }

        return null;
    }

    private static function outside(string $path): string
    {
        $name = basename($path);

        return "deploy.me only sees your ~/.ssh folder (mounted read-only into its containers), so it can’t use {$path}. Copy the key there first: cp {$path} ~/.ssh/{$name} && chmod 600 ~/.ssh/{$name}";
    }

    /**
     * The file's first line, at most 127 bytes of it: enough for any header.
     */
    private static function firstLine(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $line = fgets($handle, 128);
        } finally {
            fclose($handle);
        }

        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $line) ?? '');
    }
}
