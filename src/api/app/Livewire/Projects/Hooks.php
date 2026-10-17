<?php

namespace App\Livewire\Projects;

use App\Deploy\Pipeline\Steps\RunMigrations;
use App\Enums\HookPhase;
use App\Enums\HookRunIn;
use App\Models\Project;
use App\Models\ProjectHook;
use App\Support\HookPresets;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Commands that run at fixed points of a deploy: after the upload, before
 * the switch (release) and once the release is live (after activate).
 */
class Hooks extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public ?string $formPhase = null;

    #[Locked]
    public ?int $editing = null;

    public string $hookName = '';

    public string $command = '';

    public string $runIn = 'host';

    public string $service = '';

    // Untyped: a cleared number field arrives as an empty string.
    public $timeout = 300;

    public bool $continueOnError = false;

    public ?string $notice = null;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    public function addPreset(string $preset): void
    {
        if (! array_key_exists($preset, HookPresets::LABELS)) {
            return;
        }

        $project = $this->project;
        $existing = $project->hooks()->pluck('command')->all();
        $added = 0;

        foreach (HookPresets::for($preset, $project) as $hook) {
            if (! in_array($hook['command'], $existing, true)) {
                $project->hooks()->create([...$hook, 'position' => $this->nextPosition($hook['phase'])]);
                $added++;
            }
        }

        $this->notice = $added > 0
            ? "Added {$added} ".Str::plural('hook', $added).' from '.HookPresets::LABELS[$preset].'.'
            : 'Those hooks are already here.';
    }

    public function startAdding(string $phase): void
    {
        $phase = HookPhase::tryFrom($phase);
        if ($phase === null) {
            return;
        }

        $this->resetForm();
        $this->formPhase = $phase->value;

        $service = $this->defaultService();
        if ($this->project->dockerized && $service !== '') {
            $this->runIn = ($phase === HookPhase::AfterActivate ? HookRunIn::ServiceExec : HookRunIn::ServiceRun)->value;
            $this->service = $service;
        }
    }

    public function edit(int $id): void
    {
        $hook = $this->project->hooks()->findOrFail($id);

        $this->resetForm();
        $this->editing = $hook->id;
        $this->formPhase = $hook->phase->value;
        $this->hookName = $hook->name;
        $this->command = $hook->command;
        $this->runIn = $hook->run_in->value;
        $this->service = (string) $hook->service;
        $this->timeout = $hook->timeout;
        $this->continueOnError = $hook->continue_on_error;
    }

    public function updatedRunIn(): void
    {
        if ($this->service === '' && HookRunIn::tryFrom($this->runIn)?->needsService()) {
            $this->service = $this->defaultService();
        }
    }

    public function save(): void
    {
        if ($this->formPhase === null) {
            return;
        }

        $needsService = HookRunIn::tryFrom($this->runIn)?->needsService() ?? false;

        $this->validate([
            'hookName' => ['required', 'string', 'max:100'],
            'command' => ['required', 'string', 'max:4000'],
            'runIn' => ['required', Rule::enum(HookRunIn::class)],
            'service' => [Rule::requiredIf($needsService), 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'timeout' => ['required', 'integer', 'between:10,3600'],
        ], ['service.required' => 'Pick the Compose service to run it in.'], ['hookName' => 'name']);

        $attributes = [
            'phase' => $this->formPhase,
            'name' => $this->hookName,
            'command' => trim($this->command),
            'run_in' => $this->runIn,
            'service' => $needsService ? $this->service : null,
            'timeout' => (int) $this->timeout,
            'continue_on_error' => $this->continueOnError,
        ];

        if ($this->editing !== null) {
            $this->project->hooks()->findOrFail($this->editing)->update($attributes);
            $this->notice = "Saved {$this->hookName}.";
        } else {
            $this->project->hooks()->create([...$attributes, 'position' => $this->nextPosition(HookPhase::from($this->formPhase))]);
            $this->notice = "Added {$this->hookName}.";
        }

        $this->resetForm();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $hook = $this->project->hooks()->findOrFail($id);
        $hook->delete();

        $this->notice = "Removed {$hook->name}.";
    }

    /**
     * Moves a hook up (-1) or down (1) within its phase.
     */
    public function move(int $id, int $direction): void
    {
        $hook = $this->project->hooks()->findOrFail($id);
        $order = $this->project->hooks()->where('phase', $hook->phase)->get()->values()->all();
        $index = array_search($hook->id, array_map(fn (ProjectHook $item) => $item->id, $order), true);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= count($order)) {
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        foreach ($order as $position => $item) {
            $item->update(['position' => $position]);
        }
    }

    private function nextPosition(HookPhase $phase): int
    {
        return (int) $this->project->hooks()->where('phase', $phase)->max('position') + 1;
    }

    private function defaultService(): string
    {
        $project = $this->project;

        return (string) ($project->migrate_service ?? $project->app_services[0] ?? $project->detected()->composeServices()[0] ?? '');
    }

    private function resetForm(): void
    {
        $this->reset('formPhase', 'editing', 'hookName', 'command', 'runIn', 'service', 'timeout', 'continueOnError');
        $this->resetValidation();
    }

    public function render(): View
    {
        $project = $this->project;

        return view('livewire.projects.hooks', [
            'phases' => HookPhase::cases(),
            'hooks' => $project->hooks()->get()->groupBy(fn (ProjectHook $hook) => $hook->phase->value),
            'runIns' => HookRunIn::cases(),
            'services' => array_values(array_unique([...$project->detected()->composeServices(), ...($project->app_services ?? [])])),
            'presets' => HookPresets::LABELS,
            'dockerized' => $project->dockerized,
            'migratesItself' => RunMigrations::availableFor($project),
        ]);
    }
}
