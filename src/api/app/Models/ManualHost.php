<?php

namespace App\Models;

use App\Platform\Ssh\KeyFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A server entered by hand (host, user, port, the key's path, a jump host)
 * rather than picked from ~/.ssh/config. SshConfigCompiler writes a Host
 * block for it, so everything that connects by alias works the same for it.
 */
#[Fillable(['alias', 'host_name', 'user', 'port', 'identity_file', 'proxy_jump'])]
class ManualHost extends Model
{
    /** How deploy.me and ssh call it, like kz-prod: nothing ssh could read as an option or a pattern. */
    public const ALIAS_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    /** The login name on the server. */
    public const USER_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    /**
     * The server added with it, once there is one.
     *
     * @return HasOne<Server, $this>
     */
    public function server(): HasOne
    {
        return $this->hasOne(Server::class, 'ssh_alias', 'alias')->whereNull('archived_at');
    }

    public function keyFile(): ?KeyFile
    {
        return KeyFile::fromStored($this->identity_file);
    }

    /**
     * What the compiler writes for it, the key as the containers see it.
     *
     * @return array{alias: string, host: string, user: string, port: int, key: ?string, jump: ?string}
     */
    public function toConfig(): array
    {
        return [
            'alias' => $this->alias,
            'host' => $this->host_name,
            'user' => $this->user,
            'port' => $this->port,
            'key' => $this->keyFile()?->path(),
            'jump' => $this->proxy_jump ?: null,
        ];
    }

    /**
     * user@host, plus the port when it isn't 22: what `ssh` would be told.
     */
    public function connectionLabel(): string
    {
        return $this->user.'@'.$this->host_name.($this->port !== 22 ? ':'.$this->port : '');
    }
}
