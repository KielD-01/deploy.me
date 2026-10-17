<?php

namespace App\Livewire\Console;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * An htop pane: one server's latest probe as meters, its busiest processes and
 * its Compose stacks. Without a server named it shows the first one online.
 */
class Htop extends Component
{
    public ?string $pane = null;

    public ?string $arg = null;

    public ?int $serverId = null;

    public function mount(?string $pane = null, ?string $arg = null): void
    {
        $this->pane = $pane;
        $this->arg = $arg;
        $query = Server::query()->active();
        $this->serverId = $arg !== null
            ? (clone $query)->where(fn ($where) => $where->where('slug', $arg)->orWhere('ssh_alias', $arg))->value('id')
            : ((clone $query)->where('status', 'online')->orderBy('name')->value('id') ?? (clone $query)->orderBy('name')->value('id'));
    }

    #[On('echo-private:servers,.probed')]
    public function refresh(): void {}

    /**
     * @return array{fill: string, rest: string}
     */
    public static function meter(?float $percent, int $width = 28): array
    {
        $filled = (int) round(max(0, min(100, (float) $percent)) / 100 * $width);

        return ['fill' => str_repeat('|', $filled), 'rest' => str_repeat(' ', $width - $filled)];
    }

    public function render(): View
    {
        $server = $this->serverId !== null ? Server::query()->with('latestSnapshot')->find($this->serverId) : null;
        $snapshot = $server?->latestSnapshot;
        $disk = $snapshot !== null ? collect($snapshot->disks ?? [])->sortByDesc(fn ($disk) => $disk['size'] > 0 ? $disk['used'] / $disk['size'] : 0)->first() : null;

        return view('livewire.console.htop', [
            'server' => $server,
            'snapshot' => $snapshot,
            'disk' => $disk,
            'cpu' => collect($snapshot->processes['cpu'] ?? [])->take(8)->all(),
            'mem' => collect($snapshot->processes['mem'] ?? [])->take(8)->all(),
        ]);
    }
}
