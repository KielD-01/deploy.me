<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetupCommand extends Command
{
    protected $signature = 'deployme:setup';

    protected $description = 'Generate keys and run migrations (then `deployme:enroll` for your passkey)';

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

        if (! User::query()->exists()) {
            $this->components->info('Next: run `make enroll` to create your account and register a passkey.');
        }

        $this->components->warn('Back up APP_KEY from src/api/.env. Without it, stored tokens and env files can’t be decrypted.');

        return self::SUCCESS;
    }
}
