<?php

namespace App\Livewire\Console\Concerns;

use Illuminate\Contracts\View\View;

/**
 * A pane whose data comes after the page: the session renders it deferred
 * (`defer`), so the tmux frame shows at once with this placeholder in the
 * pane, and the pane loads in a request of its own right after.
 */
trait LoadsAfterThePage
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function placeholder(array $params = []): View
    {
        return view('livewire.console.partials.loading', [
            'command' => trim(strtolower(class_basename(static::class)).' '.($params['arg'] ?? '')),
        ]);
    }
}
