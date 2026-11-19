<?php

namespace App\Livewire\Concerns;

use App\Actions\Servers\SaveManualHost;
use App\Models\ManualHost;
use App\Platform\Ssh\KeyFile;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/**
 * The fields of a server entered by hand (Add server → Enter manually, and
 * the SSH connection on a server's Settings tab). SaveManualHost checks them;
 * its problems land on these properties' names.
 */
trait EditsManualHost
{
    // The fields checked as you leave them, and SaveManualHost's names for them.
    private const MANUAL_FIELDS = ['manualAlias' => 'alias', 'manualHost' => 'host', 'manualUser' => 'user', 'manualPort' => 'port', 'manualKey' => 'key', 'manualJump' => 'jump'];

    public string $manualName = '';

    public string $manualAlias = '';

    // Until the alias is typed in, it follows the name.
    public bool $manualAliasEdited = false;

    public string $manualHost = '';

    public string $manualUser = '';

    public string $manualPort = '22';

    public string $manualKey = '';

    public string $manualJump = '';

    public function updatedManualName(): void
    {
        if (! $this->manualAliasEdited) {
            $this->manualAlias = SaveManualHost::suggestAlias($this->manualName);
        }
    }

    public function updatedManualAlias(): void
    {
        $this->manualAliasEdited = trim($this->manualAlias) !== '';
    }

    /**
     * Leaving a field (wire:model.live.blur) checks it: its own problem shows,
     * or goes, then. The others wait for Continue or Save.
     */
    public function updated(string $property): void
    {
        if (! isset(self::MANUAL_FIELDS[$property])) {
            return;
        }

        $this->resetValidation($property);

        try {
            app(SaveManualHost::class)->validate($this->manualInput(), $this->manualHostBeingEdited());
        } catch (ValidationException $e) {
            if (($message = $e->errors()[self::MANUAL_FIELDS[$property]][0] ?? null) !== null) {
                $this->addError($property, $message);
            }
        }
    }

    /**
     * The saved server the fields belong to, if there is one yet.
     */
    abstract protected function manualHostBeingEdited(): ?ManualHost;

    protected function fillManual(ManualHost $host): void
    {
        $this->manualAlias = $host->alias;
        $this->manualAliasEdited = true;
        $this->manualHost = $host->host_name;
        $this->manualUser = $host->user;
        $this->manualPort = (string) $host->port;
        $this->manualKey = (string) $host->identity_file;
        $this->manualJump = (string) $host->proxy_jump;
    }

    /**
     * The values to store, or null with each problem under its field.
     *
     * @return array{alias: string, host_name: string, user: string, port: int, identity_file: ?string, proxy_jump: ?string}|null
     */
    protected function validateManual(?ManualHost $host): ?array
    {
        $this->resetManualErrors();

        try {
            return app(SaveManualHost::class)->validate($this->manualInput(), $host);
        } catch (ValidationException $e) {
            $this->manualErrors($e);

            return null;
        }
    }

    /**
     * Saves the fields onto $host (a new one when null), or puts each problem
     * under its field and returns null.
     */
    protected function saveManualHost(?ManualHost $host): ?ManualHost
    {
        $this->resetManualErrors();

        try {
            $saved = app(SaveManualHost::class)($this->manualInput(), $host);
        } catch (ValidationException $e) {
            $this->manualErrors($e);

            return null;
        }

        $this->fillManual($saved);

        return $saved;
    }

    /**
     * Closes the connection ssh keeps open to the alias in this container
     * (ControlPersist), so the next check signs in with what was just saved:
     * the socket is named after the host, port, user and jump host, not the
     * key, and a new key would otherwise ride the old connection.
     */
    protected function closeOpenConnection(string $alias): void
    {
        rescue(fn () => Process::timeout(10)->run([config('deployme.ssh.binary'), '-F', app(SshConfigCompiler::class)->compile(), '-O', 'exit', $alias]), report: false);
    }

    /**
     * ssh -G names the key as the containers see it (/root/.ssh/kz_deploy):
     * shown as it was typed (~/.ssh/kz_deploy).
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    protected function targetForDisplay(array $target): array
    {
        $sshDir = rtrim(KeyFile::sshDir(), '/').'/';

        $target['identityFiles'] = array_map(
            fn (string $file) => str_starts_with($file, $sshDir) ? '~/.ssh/'.substr($file, strlen($sshDir)) : $file,
            $target['identityFiles'] ?? [],
        );

        return $target;
    }

    /**
     * @return array{alias: string, host: string, user: string, port: string, key: string, jump: string}
     */
    private function manualInput(): array
    {
        return [
            'alias' => $this->manualAlias,
            'host' => $this->manualHost,
            'user' => $this->manualUser,
            'port' => $this->manualPort,
            'key' => $this->manualKey,
            'jump' => $this->manualJump,
        ];
    }

    private function manualErrors(ValidationException $e): void
    {
        $properties = array_flip(self::MANUAL_FIELDS);

        foreach ($e->errors() as $field => $messages) {
            $this->addError($properties[$field] ?? 'manual'.ucfirst($field), $messages[0]);
        }
    }

    private function resetManualErrors(): void
    {
        $this->resetValidation(array_keys(self::MANUAL_FIELDS));
    }
}
