<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Platform\Ssh\SshConfigReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Servers')]
class Index extends Component
{
    // Any probe re-renders the list.
    #[On('echo-private:servers,.probed')]
    public function refreshServers(): void {}

    public function render(SshConfigReader $config): View
    {
        return view('livewire.servers.index', [
            'servers' => Server::query()->active()->with('latestSnapshot')->orderBy('name')->get(),
            'gitIdentities' => collect($config->aliases())->filter->looksLikeGitHost()->count(),
        ]);
    }
}
