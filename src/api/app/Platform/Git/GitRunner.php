<?php

namespace App\Platform\Git;

use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Runs git with a clean, predictable configuration: none of the system's,
 * the user's or the repository's credential helpers, hooks or pagers win
 * (command-line config outranks them all). Tokens only reach git through
 * GIT_ASKPASS for the one call that needs them; ssh goes through deploy.me's
 * compiled config, so ssh-alias remotes (gh-work:owner/repo) resolve exactly
 * as they do on the Mac.
 */
final class GitRunner
{
    private const CONFIG = [
        // Bind mounts from macOS don't carry the owner git expects.
        'safe.directory' => '*',
        // An empty value clears every helper configured anywhere else.
        'credential.helper' => '',
        'core.hooksPath' => '/dev/null',
        'core.fsmonitor' => 'false',
        'core.pager' => 'cat',
        'color.ui' => 'never',
        'advice.detachedHead' => 'false',
        'protocol.ext.allow' => 'never',
    ];

    private const NETWORK = ['push', 'fetch', 'pull', 'ls-remote', 'clone'];

    public function __construct(private readonly SshConfigCompiler $ssh) {}

    /**
     * @param  list<string>  $args
     * @param  array{username: string, token: string}|null  $auth  answers https prompts through GIT_ASKPASS
     */
    public function run(string $cwd, array $args, ?array $auth = null, int $timeout = 60, ?callable $onOutput = null): ProcessResult
    {
        if (in_array($args[0] ?? '', self::NETWORK, true)) {
            $this->ssh->compile();
        }

        try {
            return Process::path($cwd)
                ->timeout($timeout)
                ->env($this->environment($auth))
                ->run([(string) config('deployme.git.binary', 'git'), ...$args], $onOutput);
        } catch (ProcessTimedOutException) {
            throw new GitFailed('git '.($args[0] ?? '')." didn’t finish within {$timeout} s.", 124);
        }
    }

    /**
     * Like run(), but a non-zero exit throws.
     *
     * @param  list<string>  $args
     * @param  array{username: string, token: string}|null  $auth
     *
     * @throws GitFailed
     */
    public function mustRun(string $cwd, array $args, ?array $auth = null, int $timeout = 60, ?callable $onOutput = null): string
    {
        $result = $this->run($cwd, $args, $auth, $timeout, $onOutput);

        if (! $result->successful()) {
            throw GitFailed::from($result, $args);
        }

        return $result->output();
    }

    /**
     * @param  array{username: string, token: string}|null  $auth
     * @return array<string, string>
     */
    public function environment(?array $auth = null): array
    {
        $env = [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            // Reads never write: git status skips refreshing the index, which
            // matters on the read-only project mounts.
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_ASKPASS' => (string) config('deployme.git.askpass'),
            'GIT_SSH_COMMAND' => config('deployme.ssh.binary', 'ssh').' -F '.$this->ssh->path(),
            'LC_ALL' => 'C',
        ];

        $i = 0;
        foreach (self::CONFIG as $key => $value) {
            $env["GIT_CONFIG_KEY_{$i}"] = $key;
            $env["GIT_CONFIG_VALUE_{$i}"] = $value;
            $i++;
        }
        $env['GIT_CONFIG_COUNT'] = (string) $i;

        if ($auth !== null) {
            $env['DEPLOYME_GIT_USERNAME'] = $auth['username'];
            $env['DEPLOYME_GIT_TOKEN'] = $auth['token'];
        }

        return $env;
    }
}
