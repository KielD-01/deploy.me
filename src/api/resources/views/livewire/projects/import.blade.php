<div class="flex flex-col gap-5">
    @php
        use App\Enums\DeliveryStrategyKey;
        use App\Support\Format;
        use Illuminate\Support\Carbon;
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('projects.index') }}" wire:navigate class="hover:text-base-content">Projects</a>
            <x-icon name="chevron-right" :size="12" /> <span>Import</span>
        </div>
        <div class="flex items-end gap-4">
            <h1 class="text-[22px] leading-7 font-semibold tracking-tight grow">Import project</h1>
            <div class="join">
                <button type="button" wire:click="switchTo('github')" @class(['btn btn-sm join-item', 'btn-active' => $from === 'github'])><x-icon name="repo" :size="14" /> From GitHub</button>
                <button type="button" wire:click="switchTo('local')" @class(['btn btn-sm join-item', 'btn-active' => $from === 'local'])><x-icon name="laptop" :size="14" /> From local folder</button>
            </div>
        </div>
    </div>

    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @if ($from === 'local')
        <section class="card bg-base-100 border border-line">
            <div class="p-4 flex flex-col gap-2">
                <div class="flex gap-2">
                    <label class="input grow">
                        <x-icon name="folder" class="text-muted" />
                        <input type="text" wire:model="path" wire:keydown.enter="inspect" placeholder="~/Claude/my-app" class="font-mono" spellcheck="false">
                    </label>
                    <button type="button" class="btn" wire:click="$set('browsing', true)"><x-icon name="folder" /> Browse</button>
                    <button type="button" class="btn btn-primary" wire:click="inspect" wire:loading.attr="disabled" wire:target="inspect">
                        <x-icon name="search" wire:loading.remove wire:target="inspect" />
                        <x-icon name="loader" class="animate-spin" wire:loading wire:target="inspect" /> Inspect
                    </button>
                </div>
                <p class="text-xs text-muted">
                    Folders under {{ collect($roots)->map(fn ($root) => Format::homePath($root['path']))->implode(', ') }} are available.
                    They’re mounted at the same paths inside deploy.me.
                </p>
            </div>
        </section>
    @endif

    @php
        $git = $detected?->git();
        $github = $detected?->github();
        $needsPush = $github !== null && (($git['ahead'] ?? 0) > 0 || ($git !== null && $git['upstream'] === null));
        $showPush = $from === 'local' && $git !== null && ($github === null || $needsPush || $publishing);
    @endphp

    <div @class(['grid gap-4 items-start', 'grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]' => $from === 'github' || $showPush])>
        @if ($from === 'github')
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Pick a repository</h2>
                    @if ($owners !== [])
                        <span class="text-[13px] text-muted">{{ count($this->githubRepositories['list']) }} {{ Str::plural('repository', count($this->githubRepositories['list'])) }}</span>
                    @endif
                </header>

                @if ($owners === [])
                    <p class="p-4 text-[13px] text-muted">
                        Add a GitHub token in <a href="{{ route('settings', ['tab' => 'github']) }}" wire:navigate class="link">Settings</a> to list your repositories,
                        or import a local folder instead.
                    </p>
                @else
                    @foreach ($this->githubRepositories['errors'] as $error)
                        <div class="px-4 pt-3"><div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> {{ $error }}</div></div>
                    @endforeach
                    <div class="flex gap-2 p-3 border-b border-line">
                        <select wire:model.live="owner" class="select select-sm w-52">
                            <option value="">All owners</option>
                            @foreach ($owners as $choice)
                                <option value="{{ $choice }}">{{ $choice }}</option>
                            @endforeach
                        </select>
                        <label class="input input-sm grow">
                            <x-icon name="search" :size="14" class="text-muted" />
                            <input type="search" wire:model.live.debounce.250ms="filter" placeholder="Filter repositories">
                        </label>
                    </div>
                    <ul class="max-h-[560px] overflow-y-auto">
                        @forelse ($this->visibleRepositories as $item)
                            <li wire:key="repository-{{ $item['full_name'] }}">
                                <button type="button" wire:click="selectRepository(@js($item['full_name']))" @disabled($item['imported'])
                                        @class([
                                            'w-full flex items-center gap-3 px-4 py-3 border-b border-line text-left',
                                            'hover:bg-base-200/60' => ! $item['imported'],
                                            'bg-primary/5' => $repository === $item['full_name'],
                                            'opacity-60 cursor-default' => $item['imported'],
                                        ])>
                                    <x-icon name="repo" class="text-muted" />
                                    <span class="flex flex-col grow min-w-0">
                                        <span class="font-semibold truncate">{{ $item['name'] }} <span class="font-normal text-muted">· {{ $item['owner'] }}</span></span>
                                        <span class="text-xs text-muted">{{ $item['language'] ?? 'No language' }} · updated {{ $item['updated_at'] ? Carbon::parse($item['updated_at'])->diffForHumans() : 'never' }}</span>
                                    </span>
                                    @if ($item['imported'])
                                        <span class="badge badge-sm badge-soft badge-success">Imported</span>
                                    @elseif ($item['private'])
                                        <span class="badge badge-sm badge-ghost">Private</span>
                                    @endif
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-[13px] text-muted">No repositories match.</li>
                        @endforelse
                    </ul>
                    <div wire:loading.flex wire:target="selectRepository" class="items-center gap-2 px-4 py-3 text-[13px] text-muted">
                        <x-icon name="loader" :size="14" class="animate-spin" /> Reading the repository through the GitHub API…
                    </div>
                @endif
            </section>
        @endif

        @if ($from === 'github' || $detected)
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">What we found</h2>
                    @if ($detected)
                        <span class="text-[13px] text-muted truncate">
                            {{ $from === 'github' ? 'in '.$repository.' via the GitHub API' : 'in '.Format::homePath($path).', 3 folders deep' }}
                        </span>
                    @endif
                </header>
                @if ($detected)
                    <x-detection-report :detection="$detected" />
                @else
                    <p class="px-4 py-6 text-[13px] text-muted">Pick a repository to see its workflows, Compose files and framework. Nothing is cloned.</p>
                @endif
            </section>
        @endif

        @if ($showPush)
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Push to GitHub</h2>
                        <span class="text-[13px] text-muted">Runs in the worker, which can write to your folders</span>
                    </header>

                    @if ($publishing && $publishing['state'] === 'running')
                        <div wire:poll.1s="checkPublish" class="p-4 flex items-center gap-2.5 text-[13px]">
                            <x-icon name="loader" class="animate-spin text-primary" /> {{ $publishing['step'] }}…
                        </div>
                    @else
                        @if ($publishing && $publishing['state'] === 'failed')
                            <div class="px-4 pt-4"><div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $publishing['message'] }}</div></div>
                        @elseif ($publishing && $publishing['state'] === 'done')
                            <div class="px-4 pt-4"><div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> Pushed {{ $publishing['result']['branch'] ?? '' }} to GitHub.</div></div>
                        @endif

                        @if ($github === null)
                            @if ($owners === [])
                                <p class="p-4 text-[13px] text-muted">Add a GitHub token in <a href="{{ route('settings', ['tab' => 'github']) }}" wire:navigate class="link">Settings</a> to create the repository from here.</p>
                            @else
                                <div class="p-4 grid grid-cols-2 gap-3">
                                    <label class="flex flex-col gap-1.5">
                                        <span class="text-[13px] font-medium">Owner</span>
                                        <select wire:model.live="publishOwner" class="select select-sm">
                                            @foreach ($owners as $choice)
                                                <option value="{{ $choice }}">{{ $choice }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="flex flex-col gap-1.5">
                                        <span class="text-[13px] font-medium">Repository</span>
                                        <input type="text" wire:model.live.debounce.300ms="publishName" class="input input-sm font-mono" spellcheck="false">
                                    </label>
                                    <label class="col-span-2 flex items-center gap-2 text-[13px]">
                                        <input type="checkbox" class="checkbox checkbox-sm" wire:model="publishPrivate"> Private · created through the GitHub API
                                    </label>
                                </div>
                                <ol class="px-4 pb-4 flex flex-col gap-2 text-[13px]">
                                    <li class="flex gap-2.5"><span class="badge badge-sm badge-ghost">1</span> Create <span class="mono">{{ $publishOwner }}/{{ $publishName }}</span></li>
                                    <li class="flex gap-2.5"><span class="badge badge-sm badge-ghost">2</span> Add it as origin and push {{ $git['branch'] }} ({{ $git['commits'] }} {{ Str::plural('commit', $git['commits']) }})</li>
                                    @if ($detected->workflows() !== [])
                                        <li class="flex gap-2.5"><span class="badge badge-sm badge-ghost">3</span> {{ $detected->workflows()[0]['file'] }} starts on GitHub Actions</li>
                                    @endif
                                </ol>
                                <footer class="flex justify-end px-4 py-3 border-t border-line">
                                    <button type="button" class="btn btn-primary btn-sm" wire:click="publish"><x-icon name="deploy" :size="14" /> Create and push</button>
                                </footer>
                            @endif
                        @elseif ($needsPush)
                            <p class="p-4 text-[13px]">
                                {{ $git['upstream'] === null
                                    ? 'This folder has no record of '.$git['branch'].' on '.$github['remote'].', so it may not be on GitHub yet.'
                                    : $git['ahead'].' '.Str::plural('commit', $git['ahead']).' on '.$git['branch'].' '.($git['ahead'] === 1 ? 'isn’t' : 'aren’t').' on GitHub yet.' }}
                                CI and the GHCR image only exist for commits GitHub has.
                            </p>
                            <footer class="flex justify-end px-4 py-3 border-t border-line">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="publish"><x-icon name="deploy" :size="14" /> Push {{ $git['branch'] }} to {{ $github['remote'] }}</button>
                            </footer>
                        @endif
                    @endif
                </section>
        @endif
    </div>

    @if ($detected)
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Deploy settings</h2>
                <span class="text-[13px] text-muted">Every server this project goes to uses them</span>
            </header>
            <div class="p-4 flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-box border border-line p-4 flex flex-col gap-3">
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model.live="versionable">
                            <span class="font-semibold">Versionable</span>
                            <span class="ml-auto text-xs text-muted">{{ $versionable ? 'Keep '.$keepReleases.' '.Str::plural('release', $keepReleases) : 'Deploys in place' }}</span>
                        </label>
                        <p class="text-[13px] text-muted">Each deploy gets its own folder in releases/. current points at the live one, so rollback is a symlink switch.</p>
                        @if ($versionable)
                            <x-keep-slider model="keepReleases" :value="$keepReleases" />
                            @error('keepReleases')<span class="text-error text-xs">{{ $message }}</span>@enderror
                        @endif
                    </div>

                    <div class="rounded-box border border-line p-4 flex flex-col gap-3">
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model.live="dockerized" @disabled($detected->composeFiles() === [])>
                            <span class="font-semibold">Dockerized</span>
                        </label>
                        <p class="text-[13px] text-muted">
                            {{ $detected->composeFiles() === [] ? 'No Compose file was found, so the project deploys without Docker.' : 'Runs as a Compose project on the server. The stack name comes from name: in the compose file.' }}
                        </p>
                        @if ($dockerized)
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-2.5">
                                <label class="flex flex-col gap-1.5">
                                    <span class="text-[13px] font-medium">Stack name</span>
                                    <input type="text" wire:model="stackName" class="input input-sm font-mono" spellcheck="false">
                                    @error('stackName')<span class="text-error text-xs">{{ $message }}</span>@enderror
                                </label>
                                <label class="flex flex-col gap-1.5">
                                    <span class="text-[13px] font-medium">Compose file</span>
                                    <select wire:model="composeFile" class="select select-sm font-mono">
                                        @foreach ($detected->composeFiles() as $file)
                                            <option value="{{ $file['file'] }}">{{ $file['file'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('composeFile')<span class="text-error text-xs">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <label class="flex flex-col gap-1.5 w-52">
                        <span class="text-[13px] font-medium">Name</span>
                        <input type="text" wire:model="name" class="input input-sm" maxlength="100">
                        @error('name')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5 w-52">
                        <span class="text-[13px] font-medium">Slug</span>
                        <input type="text" wire:model="slug" class="input input-sm font-mono" spellcheck="false">
                        <span class="text-xs text-muted">Folder name on servers</span>
                        @error('slug')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5 w-44">
                        <span class="text-[13px] font-medium">Branch</span>
                        @php $branches = $detected->git()['branches'] ?? []; @endphp
                        @if (count($branches) > 1)
                            <select wire:model="branch" class="select select-sm">
                                @foreach ($branches as $choice)
                                    <option value="{{ $choice }}">{{ $choice }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" wire:model="branch" class="input input-sm" spellcheck="false">
                        @endif
                        @error('branch')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex flex-col gap-1.5 grow min-w-0">
                        <span class="text-[13px] font-medium">Delivery, in order</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (collect($strategies)->where('enabled', true)->values() as $i => $strategy)
                                @php $key = DeliveryStrategyKey::from($strategy['key']); @endphp
                                <span class="badge badge-ghost h-8 gap-1.5 px-3">
                                    <x-icon :name="$key->icon()" :size="12" class="text-muted" />
                                    {{ $i + 1 }} {{ $key->label() }}{{ $key === DeliveryStrategyKey::GhcrImage && $detected->github() === null ? ' · after the first push' : '' }}
                                </span>
                            @endforeach
                        </div>
                        <span class="text-xs text-muted">Reorder or switch them in the project’s settings.</span>
                    </div>
                </div>
            </div>
            <footer class="flex items-center gap-2 px-4 py-3 border-t border-line">
                <a href="{{ route('projects.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
                <span class="grow"></span>
                <button type="button" class="btn btn-primary" wire:click="import" wire:loading.attr="disabled" wire:target="import"><x-icon name="check" /> Import project</button>
            </footer>
        </section>
    @endif

    @if ($browsing)
        <div class="modal modal-open" role="dialog" aria-label="Pick a folder">
            <div class="modal-box max-w-2xl p-0">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Pick a folder</h2>
                    <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="$set('browsing', false)" aria-label="Close"><x-icon name="x" /></button>
                </header>
                <livewire:local.folder-picker wire:model="path" />
                <div class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="usePath" @disabled($path === '')>Use {{ $path === '' ? 'this folder' : Format::homePath($path) }}</button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="$set('browsing', false)"></div>
        </div>
    @endif
</div>
