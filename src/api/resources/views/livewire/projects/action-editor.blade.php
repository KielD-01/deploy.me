{{-- One action (App\Livewire\Projects\ActionEditor): the editor for one made here, or what a repo
     file says (read-only: it's edited in git) and its runs. --}}
<div class="flex flex-col gap-5">
    @php
        use App\Deploy\Actions\ActionDefinition;
        use App\Livewire\Projects\Actions;
        use Illuminate\Support\Str;
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('projects.index') }}" wire:navigate class="hover:text-base-content">Projects</a>
            <x-icon name="chevron-right" :size="12" />
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:text-base-content">{{ $project->name }}</a>
            <x-icon name="chevron-right" :size="12" />
            <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'actions']) }}" wire:navigate class="hover:text-base-content">Actions</a>
        </div>
        <div class="flex items-end gap-4 flex-wrap">
            <div class="flex items-center gap-2.5 grow min-w-0">
                <h1 class="text-[22px] leading-7 font-semibold tracking-tight">{{ $action?->name ?? ($name !== '' ? $name : 'New action') }}</h1>
                <span class="badge badge-ghost">{{ $readOnly ? 'From the repo' : 'Made here' }}</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($readOnly)
                    @if ($definition?->manual())
                        <button type="button" class="btn btn-sm btn-primary" wire:click="$dispatch('open-action-dialog', { actionId: {{ $action->id }} })"><x-icon name="play" :size="14" /> Run</button>
                    @endif
                @else
                    <button type="button" class="btn btn-sm" wire:click="showYaml"><x-icon name="copy" :size="14" /> Copy as YAML</button>
                    <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'actions']) }}" wire:navigate class="btn btn-sm">Cancel</a>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save</button>
                @endif
            </div>
        </div>
    </div>

    @if ($problems !== [])
        <div role="alert" class="alert alert-soft alert-error text-[13px] items-start">
            <x-icon name="x-circle" />
            <div class="flex flex-col gap-0.5">
                <span class="font-semibold">It doesn’t save like this:</span>
                @foreach ($problems as $problem)<span>{{ $problem }}</span>@endforeach
            </div>
        </div>
    @endif

    @if ($readOnly)
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start">
            <section class="card bg-base-100 border border-line">
                <header class="px-4 py-2.5 border-b border-line flex items-center gap-2">
                    <x-icon name="file" :size="14" class="text-muted" />
                    <span class="mono text-[13px]">{{ $action->file }}</span>
                    @if ($action->commit_sha)<span class="text-xs text-muted">@ {{ substr($action->commit_sha, 0, 7) }}</span>@endif
                    <span class="ml-auto text-xs text-muted">edited in git · read {{ $action->loaded_at?->diffForHumans() }}</span>
                </header>
                @if ($repoYaml !== null)
                    <pre class="mono text-[12px] leading-5 p-4 overflow-x-auto bg-sunk">{{ $repoYaml }}</pre>
                @else
                    <ul class="p-4 text-[13px] text-error flex flex-col gap-1">
                        @foreach ($action->errors ?? [] as $error)<li>✕ {{ $error }}</li>@endforeach
                    </ul>
                @endif
            </section>

            <section class="card bg-base-100 border border-line">
                <header class="px-4 py-2.5 border-b border-line"><h2 class="text-[15px] font-semibold">Runs</h2></header>
                @include('livewire.projects.partials.action-runs', ['runs' => $runs])
            </section>
        </div>
    @else
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] items-start">
            <div class="flex flex-col gap-5 min-w-0">
                <section class="card bg-base-100 border border-line">
                    <div class="p-4 grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">Name</span>
                            <input type="text" wire:model.blur="name" class="input input-sm w-full" placeholder="Database snapshot" maxlength="100">
                        </label>
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">Description <span class="text-muted font-normal">(optional)</span></span>
                            <input type="text" wire:model="description" class="input input-sm w-full" maxlength="300">
                        </label>
                    </div>
                </section>

                <section class="card bg-base-100 border border-line">
                    <header class="flex items-baseline gap-2.5 px-4 py-2.5 border-b border-line flex-wrap">
                        <h2 class="text-[15px] font-semibold">Steps</h2>
                        <span class="text-[13px] text-muted">run in order; one that fails stops the rest unless it may</span>
                    </header>
                    @foreach ($steps as $i => $step)
                        <div class="flex flex-col gap-3 px-4 py-4 border-b border-line" wire:key="step-{{ $i }}">
                            <div class="flex items-center gap-2">
                                <span class="text-[13px] font-semibold shrink-0">{{ $i + 1 }} ·</span>
                                <input type="text" wire:model="steps.{{ $i }}.name" class="input input-sm grow" placeholder="Dump the database" aria-label="Step name">
                                <button type="button" class="btn btn-ghost btn-xs btn-square" wire:click="moveStep({{ $i }}, -1)" @disabled($loop->first) aria-label="Move up"><x-icon name="arrow-up" :size="12" /></button>
                                <button type="button" class="btn btn-ghost btn-xs btn-square" wire:click="moveStep({{ $i }}, 1)" @disabled($loop->last) aria-label="Move down"><x-icon name="arrow-down" :size="12" /></button>
                                <button type="button" class="btn btn-ghost btn-xs text-error" wire:click="removeStep({{ $i }})" @disabled(count($steps) === 1)>Remove</button>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[13px] font-medium">Runs</span>
                                <div class="join">
                                    @foreach ($runIns as $option)
                                        <button type="button" wire:click="$set('steps.{{ $i }}.run_in', @js($option->value))"
                                                @disabled($option->needsService() && ! $project->dockerized)
                                                @class(['btn btn-xs join-item', 'btn-active' => $step['run_in'] === $option->value])>
                                            {{ match ($option->value) { 'host' => 'On the server', 'service_exec' => 'In a service (exec)', default => 'One-off container' } }}
                                        </button>
                                    @endforeach
                                </div>
                                @if ($step['run_in'] !== 'host')
                                    @if ($services !== [])
                                        <select wire:model="steps.{{ $i }}.service" class="select select-sm w-44 font-mono" aria-label="Service">
                                            <option value="">Service…</option>
                                            @foreach ($services as $service)<option value="{{ $service }}">{{ $service }}</option>@endforeach
                                        </select>
                                    @else
                                        <input type="text" wire:model="steps.{{ $i }}.service" class="input input-sm w-44 font-mono" placeholder="service" aria-label="Service">
                                    @endif
                                @endif
                            </div>
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">Command</span>
                                <textarea wire:model="steps.{{ $i }}.run" rows="3" class="textarea font-mono text-[12px] w-full" placeholder="php artisan optimize:clear" spellcheck="false"></textarea>
                            </label>
                            <div class="flex items-center gap-4 flex-wrap text-[13px]">
                                <label class="flex items-center gap-2">Timeout <input type="text" wire:model="steps.{{ $i }}.timeout" class="input input-sm w-24" placeholder="10m"></label>
                                <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model="steps.{{ $i }}.continue_on_error"> Continue if it fails</label>
                                <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model="steps.{{ $i }}.env_file"> Env from the project’s env file</label>
                                @if ($booleans !== [])
                                    <label class="flex items-center gap-2">Only when
                                        <select wire:model="steps.{{ $i }}.if" class="select select-sm w-36">
                                            <option value="">always</option>
                                            @foreach ($booleans as $boolean)<option value="{{ $boolean }}">{{ $boolean }} is on</option>@endforeach
                                        </select>
                                    </label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    <div class="px-4 py-3"><button type="button" class="btn btn-sm" wire:click="addStep"><x-icon name="plus" :size="14" /> Add a step</button></div>
                </section>

                <section class="card bg-base-100 border border-line">
                    <header class="flex items-baseline gap-2.5 px-4 py-2.5 border-b border-line flex-wrap">
                        <h2 class="text-[15px] font-semibold">Inputs</h2>
                        <span class="text-[13px] text-muted">asked in the run dialog, given to every step as env (over stdin, never argv)</span>
                    </header>
                    @foreach ($inputs as $i => $input)
                        <div class="grid gap-2 px-4 py-3 border-b border-line sm:grid-cols-[minmax(0,1fr)_120px_minmax(0,1fr)_minmax(0,1.4fr)_auto] items-center" wire:key="input-{{ $i }}">
                            <input type="text" wire:model.blur="inputs.{{ $i }}.name" class="input input-sm font-mono" placeholder="KEEP_DAYS" aria-label="Name">
                            <select wire:model.live="inputs.{{ $i }}.type" class="select select-sm" aria-label="Type">
                                <option value="text">text</option>
                                <option value="number">number</option>
                                <option value="boolean">yes/no</option>
                                <option value="choice">choice</option>
                                <option value="secret">secret</option>
                            </select>
                            @if ($input['type'] === 'choice')
                                <input type="text" wire:model="inputs.{{ $i }}.options" class="input input-sm" placeholder="products, orders, all" aria-label="Options">
                            @elseif ($input['type'] === 'secret')
                                <span class="text-xs text-muted">no default; masked in logs</span>
                            @else
                                <input type="text" wire:model="inputs.{{ $i }}.default" class="input input-sm" placeholder="{{ $input['type'] === 'boolean' ? 'no' : 'default' }}" aria-label="Default">
                            @endif
                            <input type="text" wire:model="inputs.{{ $i }}.description" class="input input-sm" placeholder="What it’s for" aria-label="Description">
                            <button type="button" class="btn btn-ghost btn-xs text-error" wire:click="removeInput({{ $i }})">Remove</button>
                            @if ($input['type'] === 'choice')
                                <input type="text" wire:model="inputs.{{ $i }}.default" class="input input-sm sm:col-start-3" placeholder="default option" aria-label="Default">
                            @endif
                        </div>
                    @endforeach
                    <div class="px-4 py-3 flex items-center gap-3 flex-wrap">
                        <button type="button" class="btn btn-sm" wire:click="addInput"><x-icon name="plus" :size="14" /> Add an input</button>
                        <span class="text-xs text-muted">text · number · yes/no · choice · secret (masked in logs) · the name becomes an UPPER_CASE variable</span>
                    </div>
                </section>
            </div>

            <aside class="flex flex-col gap-5">
                <section class="card bg-base-100 border border-line">
                    <header class="px-4 py-2.5 border-b border-line"><h2 class="text-[15px] font-semibold">Runs when</h2></header>
                    <div class="p-4 flex flex-col gap-2.5 text-[13px]">
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model="manual"> Someone runs it (button, shell, MCP)</label>
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model.live="scheduled" @disabled($passkey)> On a schedule</label>
                        @if ($scheduled)
                            <div class="flex gap-2 pl-6">
                                <input type="text" wire:model.live.debounce.500ms="schedule" class="input input-sm font-mono w-32" aria-label="Cron">
                                <select wire:model="scheduleTarget" class="select select-sm grow" aria-label="Target">
                                    @foreach ($projectTargets as $target)<option value="{{ $target->server->slug }}">{{ $target->server->name }}</option>@endforeach
                                </select>
                            </div>
                            <span class="pl-6 text-xs text-muted">
                                @if ($next) Cron in {{ $zone }} · next {{ $next->forViewer()->format('D j M, H:i') }} @else Five fields: minute hour day month weekday @endif
                            </span>
                        @endif
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model.live="afterDeploy" @disabled($passkey)> After a deploy</label>
                        @if ($afterDeploy)
                            <div class="join pl-6">
                                @foreach (['succeeded' => 'succeeds', 'failed' => 'fails', 'either' => 'either'] as $value => $label)
                                    <button type="button" wire:click="$set('afterDeployOn', @js($value))" @class(['btn btn-xs join-item', 'btn-active' => $afterDeployOn === $value])>{{ $label }}</button>
                                @endforeach
                            </div>
                            <span class="pl-6 text-xs text-muted">Starts once the deploy’s run has ended. An action that fails never rolls a deploy back.</span>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-line">
                    <header class="px-4 py-2.5 border-b border-line"><h2 class="text-[15px] font-semibold">Targets</h2></header>
                    <div class="p-4 flex flex-col gap-2 text-[13px]">
                        @forelse ($projectTargets as $target)
                            <label class="flex items-center gap-2" wire:key="target-{{ $target->id }}">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model="targets" value="{{ $target->server->slug }}">
                                {{ $target->server->name }}
                                <span class="badge badge-sm {{ $target->server->env_tag->badgeClass() }}">{{ $target->server->env_tag->label() }}</span>
                            </label>
                        @empty
                            <span class="text-muted">{{ $project->name }} isn’t on a server yet.</span>
                        @endforelse
                    </div>
                </section>

                <section class="card bg-base-100 border border-line">
                    <header class="px-4 py-2.5 border-b border-line"><h2 class="text-[15px] font-semibold">Safety</h2></header>
                    <div class="p-4 flex flex-col gap-2.5 text-[13px]">
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model.live="passkey"> Ask for my passkey before it runs</label>
                        @if ($passkey)<span class="pl-6 text-xs text-muted">Then only you start it: no schedule, no deploy, no agent.</span>@endif
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model="mcp" @disabled($passkey)> Allow from MCP</label>
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox checkbox-sm" wire:model="alert"> Telegram alert when it fails</label>
                        <span class="text-xs text-muted">Every run takes the folder’s deploy lock, so it never runs beside a deploy.</span>
                    </div>
                </section>
            </aside>
        </div>

        @if ($action)
            <section class="card bg-base-100 border border-line">
                <header class="px-4 py-2.5 border-b border-line"><h2 class="text-[15px] font-semibold">Runs</h2></header>
                @include('livewire.projects.partials.action-runs', ['runs' => $runs])
            </section>
        @endif
    @endif

    @if ($yaml !== null)
        <dialog class="modal modal-open" aria-label="YAML" x-data="{ copied: false }">
            <div class="modal-box max-w-2xl flex flex-col gap-3">
                <h3 class="text-[15px] font-semibold">As a file: <span class="mono">.deployme/actions/{{ $action?->key ?? \App\Models\ProjectAction::keyFor($name) }}.yml</span></h3>
                <pre class="mono text-[12px] leading-5 p-3 rounded-md bg-sunk overflow-x-auto max-h-[60vh]" x-ref="yaml">{{ $yaml }}</pre>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard?.writeText($refs.yaml.innerText).then(() => copied = true)">
                        <x-icon name="copy" :size="14" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="closeYaml">Done</button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeYaml"></div>
        </dialog>
    @endif

    <livewire:projects.run-action-dialog :project-id="$project->id" :key="'run-action-'.$project->id" />
</div>
