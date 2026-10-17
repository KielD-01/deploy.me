<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SandboxCommand extends Command
{
    protected $signature = 'deployme:sandbox';

    protected $description = 'Create the SSH key and config for the local sandbox server (alias "sandbox")';

    public function handle(): int
    {
        $dir = storage_path('app/sandbox-ssh');
        $key = "{$dir}/id_ed25519";

        File::ensureDirectoryExists($dir, 0700);

        if (! is_file($key)) {
            $result = Process::run([config('deployme.ssh.keygen_binary'), '-q', '-t', 'ed25519', '-N', '', '-C', 'deploy.me sandbox', '-f', $key]);

            if ($result->failed()) {
                $this->components->error('ssh-keygen failed: '.trim($result->errorOutput()));

                return self::FAILURE;
            }
        }

        chmod($key, 0600);

        // Picked up through config('deployme.ssh.extra_configs'), after the user's own ~/.ssh/config.
        File::put("{$dir}/ssh_config", implode("\n", [
            '# The local sandbox server (compose profile "sandbox"). Written by `php artisan deployme:sandbox`.',
            'Host sandbox',
            '  HostName deployme-sshd-target',
            '  User deploy',
            "  IdentityFile {$key}",
            '  IdentitiesOnly yes',
            '',
        ]));

        $this->components->info('Sandbox key ready. It shows up as "sandbox" in Add server once `make sandbox` has started it.');

        return self::SUCCESS;
    }
}
