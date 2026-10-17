<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetupCommand extends Command
{
    protected $signature = 'deployme:setup';

    protected $description = 'Generate keys and run migrations (on a first run also creates the account and prints its one-time link)';

    public function handle(EnvFile $env): int
    {
        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        if (blank($env->get('REVERB_APP_KEY'))) {
            $env->set([
                'REVERB_APP_ID' => (string) random_int(100000, 999999),
                'REVERB_APP_KEY' => Str::lower(Str::random(20)),
                'REVERB_APP_SECRET' => Str::lower(Str::random(40)),
            ]);
            $this->components->info('Generated Reverb credentials. Restart deployme-reverb, deployme-worker and deployme-web to load them.');
        }

        $this->call('migrate', ['--force' => true]);

        // The first run still gets its link: the sign-in page doesn't offer to
        // create the account (DEPLOYME_WEB_SETUP is off), so create it here.
        if (! User::query()->exists()) {
            $this->components->info('No account yet: creating it. `make enroll` prints a new link any time.');

            // No prompts in the middle of `make init`: the host user and its
            // default address, as `make enroll` would offer them.
            $name = config('deployme.host_user') ?: 'admin';

            if ($this->call('deployme:enroll', ['--name' => $name, '--email' => "{$name}@deployme.localhost"]) !== self::SUCCESS) {
                $this->components->warn('Run `make enroll` to create your account and register a passkey.');
            }
        }

        $this->components->warn('Back up APP_KEY from src/api/.env. Without it, stored tokens and env files can’t be decrypted.');

        return self::SUCCESS;
    }
}
