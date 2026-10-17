<div class="flex flex-col gap-5">
    @php
        use App\Enums\Activation;
        use App\Enums\CiState;
        use App\Enums\DeliveryStrategyKey;
        use App\Support\Format;
        $ci = $project->ci_state;
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('projects.index') }}" wire:navigate class="hover:text-base-content">Projects</a>
            <x-icon name="chevron-right" :size="12" /> <span>{{ $project->name }}</span>
        </div>
        <div class="flex items-end gap-4">
            <div class="flex flex-col gap-1 grow min-w-0">
                <div class="flex items-center gap-2.5">
                    <h1 class="text-[22px] leading-7 font-semibold tracking-tight">{{ $project->name }}</h1>
                    @if ($ci)
                        @php $badge = 'badge gap-1 '.$ci->badgeClass(); @endphp
                        @if ($ci->hasSignal() && ($project->ci_run['url'] ?? null))
                            <a href="{{ $project->ci_run['url'] }}" target="_blank" rel="noopener" class="{{ $badge }}" title="{{ $project->ci_run['name'] ?? 'Workflow' }} #{{ $project->ci_run['number'] ?? '' }}">
                                <x-icon :name="$ci->icon()" :size="12" @class(['animate-spin' => $ci === CiState::Running]) /> CI {{ Str::lower($ci->label()) }}
                            </a>
                        @else
                            <span class="{{ $badge }}"><x-icon :name="$ci->icon()" :size="12" /> {{ $ci->hasSignal() ? 'CI '.Str::lower($ci->label()) : $ci->label() }}</span>
                        @endif
                    @endif
                    @if ($project->hasGitHub())
                        <button type="button" class="btn btn-ghost btn-xs btn-square text-muted" wire:click="checkCi" title="Check CI now" aria-label="Check CI now">
                            <x-icon name="refresh" :size="12" wire:loading.class="animate-spin" wire:target="checkCi" />
                        </button>
                    @endif
                </div>
                <p class="text-muted">
                    <span class="mono">{{ $project->sourceLabel() }}</span> · {{ $project->branch }}
                    · {{ $project->versionable ? 'releases, keep '.$project->keep_releases : 'in place' }}
                    @if ($project->dockerized) · docker stack {{ $project->stack_name }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($project->githubUrl())
                    <a href="{{ $project->githubUrl() }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="external" :size="14" /> Open on GitHub</a>
                @endif
                @if ($targetCount)
                    {{-- The dialog works out how to deliver before it shows, which can take a
                         moment: the button spins, and ignores more clicks, until DeployDialog
                         says it's open. --}}
                    <button type="button" class="btn btn-sm btn-primary"
                            x-data="{ opening: false }" x-bind:class="{ 'btn-disabled': opening }" x-bind:aria-disabled="opening"
                            x-on:click="if (opening) return; opening = true; setTimeout(() => opening = false, 30000); $wire.dispatch('open-deploy-dialog')"
                            x-on:deploy-dialog-opened.window="opening = false">
                        <x-icon name="deploy" :size="14" x-show="! opening" /><x-icon name="loader" :size="14" class="animate-spin" x-show="opening" x-cloak /> Deploy
                    </button>
                @else
                    <span class="tooltip tooltip-left" data-tip="Assign the project to a server first">
                        <button type="button" class="btn btn-sm btn-primary" disabled><x-icon name="deploy" :size="14" /> Deploy</button>
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if ($green)
        <div role="status" class="alert alert-soft alert-success text-[13px]">
            <x-icon name="deploy" />
            <span>
                <span class="mono">{{ $green['short'] }}</span> passed
                @if ($green['url'])
                    <a href="{{ $green['url'] }}" target="_blank" rel="noopener" class="link">{{ $green['workflow'] ?? 'CI' }}{{ $green['number'] ? ' #'.$green['number'] : '' }}</a>
                @else
                    {{ $green['workflow'] ?? 'CI' }}
                @endif
                and isn’t live on {{ implode(', ', $green['servers']) }} yet.
            </span>
            <button type="button" class="btn btn-sm btn-success"
                    x-data="{ opening: false }" x-bind:class="{ 'btn-disabled': opening }" x-bind:aria-disabled="opening"
                    x-on:click="if (opening) return; opening = true; setTimeout(() => opening = false, 30000); $wire.dispatch('open-deploy-dialog', { commit: @js($green['sha']) })"
                    x-on:deploy-dialog-opened.window="opening = false">
                <x-icon name="deploy" :size="14" x-show="! opening" /><x-icon name="loader" :size="14" class="animate-spin" x-show="opening" x-cloak /> Deploy {{ $green['short'] }}
            </button>
        </div>
    @endif

    <div role="tablist" class="tabs tabs-border">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', @js($key))" @class(['tab gap-1.5', 'tab-active' => $tab === $key])>
                {{ $label }}
                @if ($key === 'targets' && $targetCount)<span class="badge badge-xs badge-ghost">{{ $targetCount }}</span>@endif
                @if ($key === 'environment' && $envCount)<span class="badge badge-xs badge-ghost">{{ $envCount }}</span>@endif
                @if ($key === 'hooks' && $hookCount)<span class="badge badge-xs badge-ghost">{{ $hookCount }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px] max-w-4xl"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px] max-w-4xl"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @if ($tab === 'targets')
        <x-audit-trail :entries="$trail" :link="route('audit', ['project' => $project->id])" class="order-last" />
        <livewire:projects.targets :project-id="$project->id" :key="'targets-'.$project->id" />
    @endif

    @if ($tab === 'releases')
        <livewire:projects.releases :project-id="$project->id" :key="'releases-'.$project->id" />
    @endif

    @if ($tab === 'environment')
        <livewire:projects.environment :project-id="$project->id" :key="'environment-'.$project->id" />
    @endif

    @if ($tab === 'hooks')
        <livewire:projects.hooks :project-id="$project->id" :key="'hooks-'.$project->id" />
    @endif

    @if ($tab === 'settings')
        @foreach ($raceWarnings as $warning)
            <div role="alert" class="alert alert-soft alert-warning text-[13px] max-w-4xl">
                <x-icon name="alert" />
                <div class="flex flex-col">
                    <span class="font-semibold">GitHub Actions deploys this project too</span>
                    <span>{{ $warning['workflow'] }} runs {{ $warning['script'] ?? 'a deploy' }} over SSH on every push to {{ implode(', ', $warning['branches'] ?? []) }}. Turn that job off, or deploy.me and CI will race each other.</span>
                </div>
                @if ($project->githubUrl())
                    <a href="{{ $project->githubUrl() }}/blob/{{ $project->branch }}/.github/workflows/{{ $warning['workflow'] }}" target="_blank" rel="noopener" class="btn btn-sm">Show the job</a>
                @endif
            </div>
        @endforeach

        <form wire:submit="save" class="flex flex-col gap-4 max-w-4xl">
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Releases</h2>
                </header>
                <div class="p-4 flex flex-col gap-3">
                    <label class="flex items-center gap-2.5">
                        <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model.live="versionable">
                        <span class="font-semibold">Versionable</span>
                        @if ($versionable)<span class="ml-auto text-xs text-muted">Keep {{ $keepReleases }} {{ Str::plural('release', $keepReleases) }}</span>@endif
                    </label>
                    <p class="text-[13px] text-muted">releases/ keeps one folder per deploy, shared/ holds env files and storage, and current points at the live release.</p>
                    @if ($versionable)
                        <x-keep-slider model="keepReleases" :value="$keepReleases" class="max-w-xl" />
                        @error('keepReleases')<span class="text-error text-xs">{{ $message }}</span>@enderror
                        <label class="flex flex-col gap-1.5 max-w-xl mt-1">
                            <span class="text-[13px] font-medium">Shared folders</span>
                            <textarea wire:model="sharedPaths" rows="2" class="textarea textarea-sm font-mono text-[12px]" placeholder="storage" spellcheck="false"></textarea>
                            <span class="text-xs text-muted">One per line, relative to the project. They live in shared/ and every release links to them, so uploads and logs survive deploys.</span>
                            @error('sharedPaths')<span class="text-error text-xs">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Docker</h2>
                </header>
                <div class="p-4 flex flex-col gap-3">
                    <label class="flex items-center gap-2.5">
                        <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model.live="dockerized" @disabled($composeFiles === [] && ! $dockerized)>
                        <span class="font-semibold">Dockerized</span>
                        @if ($composeFiles === [])<span class="text-[13px] text-muted">No Compose file in the project</span>@endif
                    </label>
                    @if ($dockerized)
                        <div class="grid grid-cols-3 gap-3">
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">Stack name</span>
                                <input type="text" wire:model="stackName" class="input input-sm font-mono" spellcheck="false">
                                @php $composeName = collect($detection->composeFiles())->firstWhere('file', $composeFile)['name'] ?? null; @endphp
                                @if ($composeName && $composeName === $stackName)
                                    <span class="text-xs text-muted">From name: in {{ $composeFile }}</span>
                                @endif
                                @error('stackName')<span class="text-error text-xs">{{ $message }}</span>@enderror
                            </label>
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">Compose file</span>
                                <select wire:model.live="composeFile" class="select select-sm font-mono">
                                    @foreach ($composeFiles as $file)
                                        <option value="{{ $file }}">{{ $file }}</option>
                                    @endforeach
                                </select>
                                @error('composeFile')<span class="text-error text-xs">{{ $message }}</span>@enderror
                            </label>
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">Migrate with</span>
                                <select wire:model.live="migrateService" class="select select-sm font-mono">
                                    <option value="">No migrations</option>
                                    @foreach ($services as $service)
                                        <option value="{{ $service }}">{{ $service }}</option>
                                    @endforeach
                                </select>
                                @if ($migrateService !== '')<span class="text-xs text-muted mono">compose run --rm {{ $migrateService }}</span>@endif
                            </label>
                        </div>
                        @if ($services !== [])
                            <div class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">App services, recreated on every deploy</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($services as $service)
                                        <label @class(['btn btn-sm gap-2 font-mono', 'btn-active' => in_array($service, $appServices, true)])>
                                            <input type="checkbox" class="checkbox checkbox-xs" value="{{ $service }}" wire:model.live="appServices"> {{ $service }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($migrateService !== '')
                            <div class="grid grid-cols-3 gap-3 border-t border-line pt-3">
                                <label class="flex flex-col gap-1.5 col-span-2">
                                    <span class="text-[13px] font-medium">Back up as</span>
                                    <input type="text" wire:model="backupRole" placeholder="DB_USERNAME" class="input input-sm font-mono" spellcheck="false">
                                    <span class="text-xs text-muted">
                                        Who <span class="mono">pg_dump</span> connects as when a deploy backs the database up first. A key in CAPITALS is read from the project’s env file,
                                        with the password beside it (<span class="mono">DB_USERNAME</span> → <span class="mono">DB_PASSWORD</span>); anything else is a role name, which
                                        connects over the database container’s own socket without a password — <span class="mono">postgres</span> for its superuser.
                                        pg_dump reads with row security off, so a role row-level security applies to can’t dump at all.
                                    </span>
                                    @error('backupRole')<span class="text-error text-xs">{{ $message }}</span>@enderror
                                </label>
                                <label class="flex flex-col gap-1.5">
                                    <span class="text-[13px] font-medium">Dumps kept</span>
                                    <input type="number" min="1" max="50" wire:model="backupKeep" class="input input-sm w-24">
                                    <span class="text-xs text-muted">In <span class="mono">.deploy/backups</span> on the server.</span>
                                    @error('backupKeep')<span class="text-error text-xs">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        @endif
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Delivery</h2>
                    <span class="text-[13px] text-muted">The first strategy that applies wins. Drag to reorder.</span>
                </header>
                <ul wire:sort="sortStrategy" class="flex flex-col">
                    @foreach ($strategies as $i => $strategy)
                        @php
                            $key = DeliveryStrategyKey::from($strategy['key']);
                            $note = $detection->strategyNote($key, $local);
                        @endphp
                        <li wire:sort:item="{{ $key->value }}" wire:key="strategy-{{ $key->value }}" class="flex items-center gap-3 px-4 py-3 border-b border-line bg-base-100">
                            <span wire:sort:handle class="cursor-grab text-muted" aria-label="Drag to reorder"><x-icon name="grip" /></span>
                            <span class="badge badge-sm badge-ghost w-6">{{ $i + 1 }}</span>
                            <x-icon :name="$key->icon()" class="text-muted" />
                            <div class="flex flex-col grow min-w-0">
                                <span @class(['font-semibold', 'text-muted' => ! $strategy['enabled']])>{{ $key->label() }}</span>
                                <span @class(['text-[13px]', 'text-muted' => $note['ok'], 'text-warning' => ! $note['ok']])>{{ $note['text'] }}</span>
                            </div>
                            <span class="text-xs text-muted">{{ $strategy['enabled'] ? 'On' : 'Off' }}</span>
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" @checked($strategy['enabled'])
                                   wire:click="toggleStrategy(@js($key->value))" aria-label="Use {{ $key->label() }}">
                        </li>
                    @endforeach
                </ul>
                @error('strategies')<p class="px-4 pt-3 text-error text-xs">{{ $message }}</p>@enderror
                <div class="p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-2.5">
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="waitForCi">
                            <span class="font-semibold">Wait for CI before deploying</span>
                            <span class="text-[13px] text-muted">otherwise the next strategy is used straight away</span>
                        </label>
                        <button type="button" class="btn btn-sm ml-auto" wire:click="testResolution" wire:loading.attr="disabled" wire:target="testResolution">
                            <x-icon name="play" :size="14" /> Test resolution for {{ $project->branch }}
                        </button>
                    </div>
                    @if ($project->hasGitHub())
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="autoDeploy">
                            <span class="font-semibold">Deploy automatically when CI passes on {{ $project->branch }}</span>
                            <span class="text-[13px] text-muted">to every target, once per commit</span>
                        </label>
                    @endif
                    @if ($project->dockerized)
                        <div class="flex flex-col gap-2 pt-1">
                            <div class="flex items-center gap-2.5">
                                <span class="font-semibold">Image pins</span>
                                <span class="text-[13px] text-muted">which images a commit's CI publishes, and the compose variables pinned to them</span>
                                <button type="button" class="btn btn-xs ml-auto" wire:click="resetImageTemplates">Back to detection</button>
                                <button type="button" class="btn btn-xs" wire:click="addImageTemplate"><x-icon name="plus" :size="12" /> Add</button>
                            </div>
                            @forelse ($imageTemplates as $i => $template)
                                <div class="flex items-start gap-2" wire:key="image-template-{{ $i }}">
                                    <label class="flex flex-col gap-1 w-44">
                                        <input type="text" wire:model="imageTemplates.{{ $i }}.var" placeholder="IMAGE_TAG" class="input input-sm mono" spellcheck="false" aria-label="Compose variable">
                                        @error("imageTemplates.$i.var")<span class="text-error text-xs">{{ $message }}</span>@enderror
                                    </label>
                                    <select wire:model="imageTemplates.{{ $i }}.kind" class="select select-sm w-36" aria-label="What the variable holds">
                                        <option value="tag">holds the tag</option>
                                        <option value="image">holds image:tag</option>
                                    </select>
                                    <label class="flex flex-col gap-1 grow min-w-0">
                                        <input type="text" wire:model="imageTemplates.{{ $i }}.image" placeholder="ghcr.io/{owner}/{repo}-api" class="input input-sm mono w-full" spellcheck="false" aria-label="Image">
                                        @error("imageTemplates.$i.image")<span class="text-error text-xs">{{ $message }}</span>@enderror
                                    </label>
                                    <label class="flex flex-col gap-1 w-40">
                                        <input type="text" wire:model="imageTemplates.{{ $i }}.tag" placeholder="sha-{sha7}" class="input input-sm mono" spellcheck="false" aria-label="Tag">
                                        @error("imageTemplates.$i.tag")<span class="text-error text-xs">{{ $message }}</span>@enderror
                                    </label>
                                    <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="removeImageTemplate({{ $i }})" aria-label="Remove {{ $template['var'] ?: 'this pin' }}"><x-icon name="trash" :size="14" /></button>
                                </div>
                            @empty
                                <p class="text-[13px] text-muted">
                                    No image pins. Without one, ghcr.io images can’t deliver a release: the server builds the images instead.
                                </p>
                            @endforelse
                            <span class="text-xs text-muted">
                                <span class="mono">{owner}</span>, <span class="mono">{repo}</span>, <span class="mono">{sha}</span> and <span class="mono">{sha7}</span> are filled in per commit.
                                Edit these when your CI names images in a way detection reads badly.
                            </span>
                        </div>
                    @endif
                    @if ($resolutionTrace)
                        <ol class="rounded-box border border-line">
                            @foreach ($resolutionTrace as $i => $entry)
                                @php $state = \App\Deploy\Delivery\VerdictState::tryFrom($entry['state'] ?? '') ?? \App\Deploy\Delivery\VerdictState::NotApplicable; @endphp
                                <li class="flex items-start gap-2.5 px-3 py-2 border-b border-line last:border-b-0 text-[13px]">
                                    <span class="badge badge-sm badge-ghost w-6 shrink-0">{{ $i + 1 }}</span>
                                    <span class="font-semibold shrink-0">{{ $entry['label'] }}</span>
                                    <span @class(['shrink-0', $state->toneClass()])>{{ Str::lower($state->label()) }}</span>
                                    <span class="text-muted">{{ $entry['reason'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Health and rollback</h2>
                </header>
                <div class="p-4 grid grid-cols-2 gap-4">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">HTTP check</span>
                        <input type="url" wire:model="healthUrl" placeholder="https://app.example.com/up" class="input input-sm font-mono" spellcheck="false">
                        <span class="text-xs text-muted">Expect 200 within the timeout. Leave it empty to rely on Docker health checks.</span>
                        @error('healthUrl')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Health timeout</span>
                        <span class="flex items-center gap-2"><input type="number" min="10" max="1800" wire:model="healthTimeout" class="input input-sm w-28"> <span class="text-[13px] text-muted">seconds</span></span>
                        @error('healthTimeout')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5 col-span-2">
                        <span class="text-[13px] font-medium">Command check</span>
                        <input type="text" wire:model="healthCommand" placeholder="php artisan app:smoke-test" class="input input-sm font-mono" spellcheck="false">
                        <span class="text-xs text-muted">
                            The project’s own last word, run on the server in the new release’s folder once every deploy, after the Docker and HTTP checks.
                            A non-zero exit fails the deploy, so it switches back if rolling back automatically is on. It doesn’t run on the scheduled health checks.
                        </span>
                        @error('healthCommand')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Activation</span>
                        <div class="join">
                            @foreach (Activation::cases() as $mode)
                                <button type="button" wire:click="$set('activation', @js($mode->value))" @class(['btn btn-sm join-item', 'btn-active' => $activation === $mode->value])>{{ $mode->label() }}</button>
                            @endforeach
                        </div>
                        @if ($activation === Activation::Script->value)
                            <input type="text" wire:model="activationScript" placeholder="scripts/deploy.sh" class="input input-sm font-mono" spellcheck="false">
                            <span class="text-xs text-muted">deploy.me runs {{ $activationScript !== '' ? $activationScript : 'your deploy script' }} from the new release once its files are in place{{ $versionable ? ' and current points at it' : '' }}.</span>
                            @error('activationScript')<span class="text-error text-xs">{{ $message }}</span>@enderror
                        @endif
                    </div>
                    <label class="flex items-start gap-2.5 self-start pt-7">
                        <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="autoRollback">
                        <span class="flex flex-col">
                            <span class="text-[13px]">Roll back automatically when the health check fails</span>
                            <span class="text-xs text-muted">Or when the switch or an after-activate hook fails.</span>
                        </span>
                    </label>
                    @if ($dockerized && $activation === Activation::Builtin->value)
                        <label class="flex items-start gap-2.5 self-start col-span-2 border-t border-line pt-4">
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model.live="blueGreen">
                            <span class="flex flex-col">
                                <span class="text-[13px]">Start the new containers beside the old ones (blue/green)</span>
                                <span class="text-xs text-muted">
                                    The services the edge routes to start as the other colour, and the old ones retire once the new ones are healthy, so a deploy costs no downtime. Migrations have to suit both colours, because both serve for a moment.
                                    @if ($blueGreen)
                                        <span class="block mt-1">This project rolls: <span class="mono">{{ $rollable !== [] ? implode(', ', $rollable) : 'nothing yet — a service needs edge labels and a health check' }}</span>.</span>
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Source</h2>
                    <button type="button" class="btn btn-sm ml-auto" wire:click="redetect" wire:loading.attr="disabled" wire:target="redetect">
                        <x-icon name="refresh" :size="14" wire:loading.class="animate-spin" wire:target="redetect" /> Detect again
                    </button>
                </header>
                <dl class="p-4 grid grid-cols-[140px_minmax(0,1fr)] gap-y-3 gap-x-4 text-[13px] items-center">
                    @if ($project->hasGitHub())
                        <dt class="text-muted">Repository</dt>
                        <dd><a href="{{ $project->githubUrl() }}" target="_blank" rel="noopener" class="link mono">{{ $project->githubFullName() }}</a></dd>
                    @endif
                    @if ($local)
                        <dt class="text-muted">Folder</dt>
                        <dd class="mono">{{ Format::homePath($project->local_path) }}</dd>
                    @endif
                    <dt class="text-muted">Name</dt>
                    <dd>
                        <input type="text" wire:model="name" class="input input-sm w-72" maxlength="100">
                        @error('name')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </dd>
                    <dt class="text-muted">Branch</dt>
                    <dd>
                        <input type="text" wire:model="branch" class="input input-sm w-72" spellcheck="false">
                        @error('branch')<span class="text-error text-xs block">{{ $message }}</span>@enderror
                    </dd>
                    @if ($project->hasGitHub())
                        <dt class="text-muted">Token</dt>
                        <dd>
                            <select wire:model="credentialId" class="select select-sm w-72">
                                <option value="">Any token that reaches {{ $project->github_owner }}</option>
                                @foreach ($credentials as $credential)
                                    <option value="{{ $credential->id }}">{{ $credential->login }} · {{ $credential->token_hint }}</option>
                                @endforeach
                            </select>
                            @if ($credentials->isEmpty())
                                <span class="text-xs text-warning block">No token reaches {{ $project->github_owner }}: CI status and GHCR need one.</span>
                            @endif
                            @error('credentialId')<span class="text-error text-xs block">{{ $message }}</span>@enderror
                        </dd>
                    @endif
                    <dt class="text-muted">Slug</dt>
                    <dd><span class="mono">{{ $project->slug }}</span> <span class="text-xs text-muted">· the folder name on servers</span></dd>
                    <dt class="text-muted">Detected</dt>
                    <dd>{{ $project->detected_at?->diffForHumans() ?? 'Never' }}</dd>
                </dl>
                @unless ($detection->isEmpty())
                    <details class="border-t border-line">
                        <summary class="px-4 py-3 text-[13px] cursor-pointer select-none">What we found</summary>
                        <x-detection-report :detection="$detection" class="border-t border-line" />
                    </details>
                @endunless
            </section>

            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-sm btn-ghost text-error" wire:click="archive"
                        wire:confirm="Remove {{ $project->name }} from deploy.me? Nothing on your servers or on GitHub changes.">
                    <x-icon name="trash" :size="14" /> Remove project
                </button>
                <span class="grow"></span>
                <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save settings</button>
            </div>
        </form>
    @endif

    <livewire:projects.deploy-dialog :project-id="$project->id" :key="'deploy-'.$project->id" />
</div>
