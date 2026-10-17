<?php

namespace App\Console\Commands;

use App\Actions\Maintenance\CheckForUpdate;
use App\Support\Alert;
use App\Support\Updates;
use Illuminate\Console\Command;

/**
 * The app's half of `bin/update`: the host script asks it what's out, whether
 * anything is running, and what the nightly mode allows, and tells it how a run
 * ended so Telegram hears about it.
 */
class UpdateCommand extends Command
{
    protected $signature = 'deployme:update
        {action=status : status | check | plan | busy | mode | notify}
        {value? : the mode (off, patch, all) for "mode"; the text for "notify"}
        {--auto : plan as the nightly run: only what the mode allows}
        {--json : machine-readable output}';

    protected $description = 'Check for a newer deploy.me release, and answer bin/update (see docs/usage.md)';

    public function handle(CheckForUpdate $check): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'check' => $this->check($check),
            'plan' => $this->plan(),
            'busy' => $this->busy(),
            'mode' => $this->mode(),
            'notify' => $this->notify(),
            default => $this->usage(),
        };
    }

    private function status(): int
    {
        $available = Updates::available();
        $this->line('Running   '.Updates::current());
        $this->line('Newest    '.(Updates::latest()['version'] ?? 'not checked yet').($available !== null ? "  ({$available['kind']} release: bin/update)" : ''));
        $this->line('Automatic '.Updates::MODES[Updates::mode()]);

        return self::SUCCESS;
    }

    private function check(CheckForUpdate $check): int
    {
        $result = $check(force: true);
        $this->option('json') ? $this->line((string) json_encode($result)) : $this->line($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function plan(): int
    {
        $plan = Updates::plan((bool) $this->option('auto'));
        $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES));

        // 0 apply, 3 busy, 4 nothing to do: what the script branches on.
        return match ($plan['action']) {
            'apply' => self::SUCCESS,
            'busy' => 3,
            default => 4,
        };
    }

    // 3 while a deploy or a folder sync runs: the script waits instead of restarting the stack.
    private function busy(): int
    {
        return Updates::busy() ? 3 : self::SUCCESS;
    }

    private function mode(): int
    {
        $value = $this->argument('value');

        if ($value === null) {
            $this->line(Updates::mode());

            return self::SUCCESS;
        }

        try {
            Updates::setMode((string) $value);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->line('Automatic updates: '.Updates::MODES[Updates::mode()]);

        return self::SUCCESS;
    }

    private function notify(): int
    {
        $text = trim((string) $this->argument('value'));

        if ($text === '') {
            return $this->usage();
        }

        Alert::send('deploy.me: '.$text);

        return self::SUCCESS;
    }

    private function usage(): int
    {
        $this->components->error('Usage: deployme:update [status|check|plan [--auto]|busy|mode [off|patch|all]|notify <text>]');

        return self::INVALID;
    }
}
