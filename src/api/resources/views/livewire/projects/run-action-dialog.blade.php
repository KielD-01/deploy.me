{{-- The run dialog of an action (App\Livewire\Projects\RunActionDialog). --}}
<div>
    @php
        use App\Deploy\Actions\ActionDefinition;
    @endphp

    @if ($open && $definition)
        <div class="modal modal-open items-start" role="dialog" aria-label="Run {{ $definition->name() }}">
            <div class="modal-box max-w-[680px] p-0 mt-16 max-h-[calc(100dvh-8rem)] flex flex-col overflow-hidden">
                <header class="flex items-start gap-2.5 px-5 py-3 border-b border-line shrink-0">
                    <div class="flex flex-col gap-0.5 min-w-0">
                        <h2 class="text-[17px] font-semibold">Run {{ $definition->name() }}</h2>
                        <span class="text-[13px] text-muted">
                            {{ $this->project->name }} ·
                            @if ($action->fromRepo()) from <span class="mono">{{ $action->file }}</span>@if ($action->commit_sha) at {{ substr($action->commit_sha, 0, 7) }}@endif @else made here @endif
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="close" aria-label="Close"><x-icon name="x" /></button>
                </header>

                <div class="p-5 flex flex-col gap-5 min-h-0 overflow-y-auto">
                    @if ($problem)
                        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
                    @endif

                    <div class="flex flex-col gap-2">
                        <span class="text-[13px] font-medium">Target</span>
                        @if ($targets->isEmpty())
                            <span class="text-[13px] text-muted">It runs on none of the servers {{ $this->project->name }} is on.</span>
                        @endif
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($targets as $choice)
                                <button type="button" wire:click="pickTarget({{ $choice->id }})" wire:key="target-{{ $choice->id }}"
                                        @class(['rounded-box border px-3 py-2 text-left', 'border-primary bg-primary/5' => $choice->id === $targetId, 'border-line' => $choice->id !== $targetId])>
                                    <span class="block text-[13px] font-semibold">{{ $choice->server->name }}</span>
                                    <span class="block text-xs text-muted">{{ $choice->server->env_tag->label() }} · {{ $choice->currentRelease ? 'release '.($choice->currentRelease->inReleasesFolder() ? $choice->currentRelease->name : ($choice->currentRelease->shortSha() ?? 'live')) : 'nothing live yet' }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @foreach ($definition->inputs() as $input)
                        @php $name = $input['name']; @endphp
                        <div class="flex flex-col gap-1.5" wire:key="input-{{ $name }}">
                            @if ($input['type'] === 'boolean')
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model.live="values.{{ $name }}">
                                    <span class="flex flex-col">
                                        <span class="text-[13px] font-medium mono">{{ $name }}</span>
                                        @if ($input['description'])<span class="text-xs text-muted">{{ $input['description'] }}</span>@endif
                                    </span>
                                </label>
                            @else
                                <span class="text-[13px] font-medium mono">{{ $name }}</span>
                                @if ($input['type'] === 'choice')
                                    <div class="join flex-wrap">
                                        @foreach ($input['options'] as $option)
                                            <button type="button" wire:click="pick(@js($name), @js($option))" @class(['btn btn-sm join-item', 'btn-active' => ($values[$name] ?? '') === $option])>{{ $option }}</button>
                                        @endforeach
                                    </div>
                                @else
                                    <input type="{{ $input['type'] === 'secret' ? 'password' : ($input['type'] === 'number' ? 'number' : 'text') }}" wire:model="values.{{ $name }}"
                                           class="input input-sm w-full {{ $input['type'] === 'secret' ? '' : 'font-mono' }}" autocomplete="off" aria-label="{{ $name }}">
                                @endif
                                @if ($input['description'] || $input['type'] === 'secret')
                                    <span class="text-xs text-muted">{{ $input['description'] }}{{ $input['type'] === 'secret' ? ($input['description'] ? ' · ' : '').'masked in the log, kept nowhere once the run starts' : '' }}</span>
                                @endif
                            @endif
                        </div>
                    @endforeach

                    <div class="flex flex-col gap-2">
                        <span class="text-[13px] font-medium">What will run</span>
                        <ol class="rounded-box border border-line divide-y divide-line">
                            @foreach ($definition->steps() as $i => $step)
                                @php $skipped = $step['if'] !== null && ! ($values[$step['if']] ?? false); @endphp
                                <li @class(['flex flex-wrap items-center gap-2 px-3 py-2 text-[13px]', 'opacity-50' => $skipped])>
                                    <span class="font-medium">{{ $i + 1 }} · {{ $step['name'] }}</span>
                                    <span class="badge badge-sm badge-ghost">{{ ActionDefinition::where($step) }}</span>
                                    <span class="mono text-xs text-muted break-all">{{ \Illuminate\Support\Str::limit($step['run'], 120) }}</span>
                                    @if ($step['timeout'] !== ActionDefinition::DEFAULT_TIMEOUT)<span class="text-xs text-muted">up to {{ ActionDefinition::duration($step['timeout']) }}</span>@endif
                                    @if ($skipped)<span class="text-xs text-muted">skipped: {{ $step['if'] }} is off</span>@endif
                                    @if ($step['continue_on_error'])<span class="text-xs text-warning">may fail</span>@endif
                                </li>
                            @endforeach
                        </ol>
                        <span class="text-xs text-muted">
                            In <span class="mono">{{ $folder ?? 'the live release’s folder' }}</span> · takes the deploy lock, so it won’t start while a deploy runs
                            @if ($definition->inputs() !== [])
                                · inputs reach the steps as {{ implode(', ', array_map(fn (array $input) => ActionDefinition::envName($input['name']), $definition->inputs())) }}
                            @endif
                        </span>
                    </div>
                </div>

                <footer class="flex items-center gap-2 px-5 py-3 border-t border-line shrink-0">
                    @if ($target)
                        <span class="text-[13px] text-muted">{{ $target->server->env_tag->label() }} target</span>
                    @endif
                    <div class="ml-auto flex gap-2">
                        <button type="button" class="btn btn-sm" wire:click="close">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="run" wire:loading.attr="disabled" wire:target="run" @disabled(! $target)>
                            <x-icon name="play" :size="14" /> Run{{ $target ? ' on '.$target->server->name : '' }}
                        </button>
                    </div>
                </footer>
            </div>
            <div class="modal-backdrop" wire:click="close"></div>
        </div>
    @endif
</div>
