<?php

namespace App\Livewire\Console;

use App\Support\Ui;
use App\Terminal\Layout;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Console theme's session: panes you split, close, resize and point at
 * a shell, a run's log, a server's htop, a project's git log or the status of
 * every target. The layout is saved to the person's account as it changes,
 * so it's the same in every browser.
 */
class Session extends Component
{
    /** @var array<string, mixed> */
    public array $layout = [];

    public function mount(): void
    {
        $saved = Ui::preferences(auth()->user())['layouts']['current'] ?? null;
        $this->layout = Layout::normalize($saved) ?? Layout::preset('ops');
    }

    public function split(string $id, string $dir, string $kind = 'shell', ?string $arg = null): void
    {
        $this->change(Layout::split($this->layout, $id, $dir, $kind, $arg));
    }

    public function close(string $id): void
    {
        $this->change(Layout::close($this->layout, $id));
    }

    public function point(string $id, string $kind, ?string $arg = null): void
    {
        $this->change(Layout::set($this->layout, $id, $kind, $arg));
    }

    /**
     * @param  list<int>  $path
     * @param  list<float|int>  $sizes
     */
    public function resize(array $path, array $sizes): void
    {
        $this->change(Layout::resize($this->layout, array_map('intval', $path), $sizes), render: false);
    }

    public function load(string $preset): void
    {
        $this->change(Layout::preset(in_array($preset, Layout::PRESETS, true) ? $preset : 'ops'));
    }

    /**
     * Something asked for a pane (the shell's `open`, a deploy it started): a pane of
     * that kind shows it, or the pane that asked is split to make room.
     */
    #[On('console-open')]
    public function open(string $kind, ?string $arg = null, ?string $from = null): void
    {
        if (! in_array($kind, Layout::KINDS, true)) {
            return;
        }

        $same = collect(Layout::leaves($this->layout))->firstWhere('kind', $kind);

        if ($same !== null) {
            $this->point($same['id'], $kind, $arg);

            return;
        }

        $this->split($from ?? Layout::leaves($this->layout)[0]['id'], 'row', $kind, $arg);
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    private function change(array $layout, bool $render = true): void
    {
        $this->layout = Layout::normalize($layout) ?? Layout::preset('ops');

        $user = auth()->user();
        $layouts = Ui::preferences($user)['layouts'];
        Ui::save($user, ['layouts' => [...$layouts, 'current' => $this->layout]]);

        if (! $render) {
            $this->skipRender();
        }
    }

    public function render(): View
    {
        return view('livewire.console.session', ['panes' => count(Layout::leaves($this->layout))]);
    }
}
