{{-- The session's panes (App\Livewire\Console\Session). Alpine (tmuxSession) keeps which pane
     is active and zoomed, and drags the borders; the tree itself lives on the server. --}}
<div class="tmux-split" data-dir="row" x-data="tmuxSession" x-on:tmux.window="handle($event.detail)" x-bind:class="zoomed && 'is-zoomed-root'">
    @include('livewire.console.partials.node', ['node' => $layout, 'path' => [], 'only' => $panes === 1])
</div>
