<?php

namespace App\Livewire\Console;

use App\Terminal\Shell as Commands;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A status pane: every target, as `status` prints it, kept current by the
 * runs, probes and health checks that change it.
 */
class Status extends Component
{
    public ?string $pane = null;

    public ?string $arg = null;

    #[On('echo-private:deployments,.updated')]
    #[On('echo-private:projects,.health.updated')]
    public function refresh(): void {}

    public function render(Commands $shell): View
    {
        return view('livewire.console.status', ['lines' => $shell->run('status')->lines]);
    }
}
