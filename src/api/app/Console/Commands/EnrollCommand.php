<?php

namespace App\Console\Commands;

use App\Auth\EnrollmentLinks;
use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

class EnrollCommand extends Command
{
    protected $signature = 'deployme:enroll
        {--name= : Your name (first run only)}
        {--email= : The email your passkey manager shows (first run only)}';

    protected $description = 'Print a one-time link that signs you in to register a passkey (also recovers a lost one)';

    public function handle(EnrollmentLinks $links): int
    {
        $user = User::query()->first() ?? $this->createUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $this->components->info('Open this link within '.EnrollmentLinks::TTL_MINUTES.' minutes to set up a passkey or a password. It works once.');
        $this->line('  '.$links->create($user));
        $this->newLine();

        return self::SUCCESS;
    }

    private function createUser(): ?User
    {
        $default = config('deployme.host_user') ?: 'admin';
        $interactive = $this->input->isInteractive();

        $data = [
            'name' => $this->option('name') ?: ($interactive ? text('Your name', default: $default, required: true) : $default),
            'email' => $this->option('email') ?: ($interactive
                ? text('Email for your passkey manager', default: "{$default}@deployme.localhost", required: true)
                : "{$default}@deployme.localhost"),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return null;
        }

        // Nobody knows this password, and password sign-in refuses it until a real
        // one is chosen (User::hasPassword()); the column just can't be empty.
        $user = User::query()->create([...$data, 'password' => Str::random(64)]);
        Audit::record(AuditAction::AccountCreated, ['subject' => $user->email, 'detail' => 'by make enroll', 'user' => $user]);

        return $user;
    }
}
