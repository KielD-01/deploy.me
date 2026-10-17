<?php

namespace App\Livewire\Console;

use App\Terminal\Output;
use App\Terminal\Shell as Commands;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * A shell pane: a prompt, and what each command printed. A command that asks
 * something (a pick, a yes or no) waits on the prompt for the answer.
 */
class Shell extends Component
{
    // How much of the screen a pane keeps.
    public const KEEP = 60;

    #[Locked]
    public ?string $pane = null;

    public ?string $arg = null;

    /** @var list<array{prompt: ?string, lines: list<list<array{0: string, 1: string}>>}> */
    #[Locked]
    public array $screen = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $ask = null;

    public function mount(?string $pane = null, ?string $arg = null): void
    {
        $this->pane = $pane;
        $this->screen = [['prompt' => null, 'lines' => [
            [['deploy.me', 't-b'], [' shell · type ', 't-mute'], ['help', 't-run'], [' for the commands, ', 't-mute'], ['status', 't-run'], [' for every target. Tab completes.', 't-mute']],
        ]]];
    }

    public function submit(Commands $shell, string $input): void
    {
        $input = mb_substr(trim($input), 0, 300);

        if ($this->ask !== null) {
            $this->answer($shell, $input);

            return;
        }

        $this->show($input, $shell->run($input));
    }

    public function answer(Commands $shell, string $value): void
    {
        $ask = $this->ask;

        if ($ask === null) {
            return;
        }

        $this->ask = null;
        $label = $ask['type'] === 'choice'
            ? (collect($ask['options'] ?? [])->firstWhere('value', $value)['label'] ?? null)
            : $value;

        if ($ask['type'] === 'choice' && $label === null) {
            $this->print(null, [[['✕ Pick one of the choices.', 't-err']]]);
            $this->ask = $ask;

            return;
        }

        $this->print(null, [[['? ', 't-run'], [$ask['question'].' ', 't-b'], [(string) $label, 't-run']]]);
        $this->show(null, $shell->answer($ask['state'], $value));
    }

    public function cancelAsk(): void
    {
        if ($this->ask !== null) {
            $this->print(null, [[['^C', 't-mute']]]);
            $this->ask = null;
        }
    }

    /**
     * @return list<string>
     */
    #[Renderless]
    public function complete(Commands $shell, string $input): array
    {
        return array_slice($shell->complete(mb_substr($input, 0, 200)), 0, 20);
    }

    private function show(?string $input, Output $out): void
    {
        if ($out->clear) {
            $this->screen = [];

            return;
        }

        $this->print($input, $out->lines);
        $this->ask = $out->ask;

        if ($out->open !== null) {
            $this->dispatch('console-open', kind: $out->open['kind'], arg: $out->open['arg'], from: $this->pane);
        }

        if ($out->visit !== null) {
            $this->redirect($out->visit, navigate: true);
        }
    }

    /**
     * @param  list<list<array{0: string, 1: string}>>  $lines
     */
    private function print(?string $input, array $lines): void
    {
        $this->screen[] = ['prompt' => $input, 'lines' => $lines];
        $this->screen = array_slice($this->screen, -self::KEEP);
    }

    public function render(): View
    {
        return view('livewire.console.shell');
    }
}
