<?php

namespace App\Actions\Servers;

use App\Enums\AuditAction;
use App\Models\ManualHost;
use App\Models\Server;
use App\Platform\Ssh\KeyFile;
use App\Platform\Ssh\Route\Route;
use App\Platform\Ssh\SshConfigReader;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checks and saves a server entered by hand: what `ssh -i key -p port
 * user@host` says, under an alias of its own. Problems come back in a
 * ValidationException keyed by field: alias, host, user, port, key, jump.
 */
final class SaveManualHost
{
    public function __construct(private readonly SshConfigReader $reader) {}

    /**
     * @param  array{alias?: string, host?: string, user?: string, port?: string|int, key?: string, jump?: string}  $input
     */
    public function __invoke(array $input, ?ManualHost $host = null): ManualHost
    {
        $values = $this->validate($input, $host);
        $host ??= new ManualHost;
        $creating = ! $host->exists;
        $host->fill($values);
        $changed = array_keys($host->getDirty());
        $host->save();

        if ($creating || $changed !== []) {
            // Which settings changed, never their values.
            $names = ['alias' => 'alias', 'host_name' => 'host', 'user' => 'user', 'port' => 'port', 'identity_file' => 'key', 'proxy_jump' => 'jump host'];

            Audit::record(AuditAction::ManualHostSaved, [
                'subject' => $host->alias,
                'server_id' => $host->server?->id,
                'detail' => $creating ? 'added' : 'changed '.implode(', ', array_map(fn (string $column) => $names[$column] ?? $column, $changed)),
            ]);
        }

        return $host;
    }

    /**
     * The values to store, after reading `user@host:port` typed into the host
     * field the way ssh would.
     *
     * @param  array{alias?: string, host?: string, user?: string, port?: string|int, key?: string, jump?: string}  $input
     * @return array{alias: string, host_name: string, user: string, port: int, identity_file: ?string, proxy_jump: ?string}
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?ManualHost $host = null): array
    {
        $alias = trim((string) ($input['alias'] ?? ''));
        $hostName = trim((string) ($input['host'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $port = trim((string) ($input['port'] ?? ''));
        $key = trim((string) ($input['key'] ?? ''));
        $jump = trim((string) ($input['jump'] ?? ''));

        if (preg_match('/^([^@\s]+)@(\S+)$/', $hostName, $m) === 1 && ($user === '' || $user === $m[1])) {
            [$user, $hostName] = [$m[1], $m[2]];
        }

        if (preg_match('/^([^:\s]+):(\d{1,5})$/', $hostName, $m) === 1 && in_array($port, ['', '22', $m[2]], true)) {
            [$hostName, $port] = [$m[1], $m[2]];
        }

        $hostName = trim($hostName, '[]');
        $errors = [];

        if ($alias === '') {
            $errors['alias'] = 'Give it an alias: the name ssh and deploy.me call this server by, like kz-prod.';
        } elseif (preg_match(ManualHost::ALIAS_PATTERN, $alias) !== 1) {
            $errors['alias'] = 'Use letters, digits, dots, dashes and underscores (up to 64), starting with a letter or digit, like kz-prod.';
        } elseif (($problem = $this->aliasTaken($alias, $host)) !== null) {
            $errors['alias'] = $problem;
        }

        if ($hostName === '') {
            $errors['host'] = 'Type the server’s host name or IP address.';
        } elseif (! Route::isHost($hostName)) {
            $errors['host'] = 'Use a host name or an IP address, like 203.0.113.10 or vps.example.com.';
        }

        if ($user === '') {
            $errors['user'] = 'Type the user deploy.me logs in as, like deploy or root.';
        } elseif (preg_match(ManualHost::USER_PATTERN, $user) !== 1) {
            $errors['user'] = 'Use the login name on the server: letters, digits, dots, dashes and underscores, like deploy.';
        }

        if (! ctype_digit($port) || ! Route::isPort((int) $port)) {
            $errors['port'] = 'Use a port from 1 to 65535.';
        }

        $keyFile = null;

        if ($key !== '') {
            try {
                $keyFile = KeyFile::parse($key);
                $problem = $keyFile->problem();
            } catch (InvalidArgumentException $e) {
                $problem = $e->getMessage();
            }

            if ($problem !== null) {
                $errors['key'] = $problem;
            }
        }

        if ($jump !== '') {
            $problem = match (true) {
                $jump === $alias => 'A server can’t be its own jump host.',
                ! in_array($jump, $this->jumpChoices($alias), true) => 'Use a host from your ~/.ssh/config, or another server entered here.',
                ! isset($errors['alias']) && $this->leadsBackTo($jump, $alias) => "{$jump} already goes through {$alias}: that would be a loop.",
                default => null,
            };

            if ($problem !== null) {
                $errors['jump'] = $problem;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'alias' => $alias,
            'host_name' => $hostName,
            'user' => $user,
            'port' => (int) $port,
            'identity_file' => $keyFile?->display(),
            'proxy_jump' => $jump !== '' ? $jump : null,
        ];
    }

    /**
     * Hosts a server entered here can jump through: every alias in the ssh
     * config, and the other servers entered by hand.
     *
     * @return list<string>
     */
    public function jumpChoices(?string $except = null): array
    {
        return collect($this->configAliases())
            ->merge(ManualHost::query()->orderBy('alias')->pluck('alias'))
            ->reject(fn (string $alias) => $alias === $except)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An alias from the name typed in: "KZ Prod" is kz-prod.
     */
    public static function suggestAlias(string $name): string
    {
        return Str::limit(Str::slug($name), 64, '');
    }

    private function aliasTaken(string $alias, ?ManualHost $host): ?string
    {
        if ($host?->exists && $host->alias !== $alias && $host->server()->exists()) {
            return 'The alias stays as it is once a server uses it.';
        }

        if (in_array($alias, $this->configAliases(), true)) {
            return "{$alias} is already a Host in your ssh config: pick it from the list instead, or use another alias.";
        }

        if (ManualHost::query()->where('alias', $alias)->when($host?->exists, fn ($query) => $query->whereKeyNot($host->id))->exists()) {
            return "deploy.me already has a server entered by hand as {$alias}.";
        }

        if (($host === null || $host->alias !== $alias) && Server::query()->active()->where('ssh_alias', $alias)->exists()) {
            return "A server here already goes by {$alias}.";
        }

        return null;
    }

    /**
     * Whether the jump host's own jump hosts (the ones entered here) come back to $alias.
     */
    private function leadsBackTo(string $jump, string $alias): bool
    {
        $seen = [$alias];
        $next = $jump;

        while ($next !== null && count($seen) < 20) {
            if (in_array($next, $seen, true)) {
                return true;
            }

            $seen[] = $next;
            $next = ManualHost::query()->where('alias', $next)->value('proxy_jump');
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function configAliases(): array
    {
        return rescue(fn () => array_map(fn ($alias) => $alias->name, $this->reader->aliases()), [], report: false);
    }
}
