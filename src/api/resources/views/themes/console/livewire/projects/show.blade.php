{{-- The Console theme's project page: the branch as `git log --graph`, each commit tagged
     with its releases and where it's live, beside the targets and a deploy.conf of the
     settings. The other tabs keep their components. --}}
<div class="con-page" x-data="pageKeys" x-on:keydown.window="key($event)">
    @php
        use App\Enums\CiState;
        use App\Enums\TargetHealth;
        use Illuminate\Support\Str;

        $ci = $project->ci_state;
        $ciTone = match ($ci) {
            CiState::Passing => 't-ok',
            CiState::Failing => 't-err',
            CiState::Running => 't-run',
            default => 't-faint',
        };
        $healthTone = fn (TargetHealth $health) => match ($health) {
            TargetHealth::Healthy => 't-ok',
            TargetHealth::Unhealthy => 't-err',
            default => 't-faint',
        };
        $letters = ['targets' => 't', 'releases' => 'l', 'environment' => 'e', 'hooks' => 'h', 'settings' => ','];
        $names = ['targets' => 'log', 'releases' => 'releases', 'environment' => 'env', 'hooks' => 'hooks', 'settings' => 'settings'];
        $targetList = $project->targets()->active()->with(['server', 'currentRelease', 'activeDeployment'])->orderBy('id')->get();
        $yes = fn (bool $on) => [$on ? 'yes' : 'no', $on ? 't-ok' : 't-mute'];
        $conf = array_filter([
            'layout' => [$project->versionable ? 'releases, keep '.$project->keep_releases : 'in place', ''],
            'shared' => $project->versionable ? [implode(', ', $project->sharedPaths()) ?: '—', ''] : null,
            'docker' => [$project->dockerized ? 'stack '.$project->stack_name.' · '.($project->compose_files[0] ?? '') : 'no', $project->dockerized ? '' : 't-mute'],
            'delivery' => [collect($project->delivery_strategies ?? [])->where('enabled', true)->pluck('key')->implode(' > ') ?: '—', ''],
            'wait_for_ci' => $yes((bool) $project->wait_for_ci),
            'auto_deploy' => $project->hasGitHub() ? $yes((bool) $project->auto_deploy) : null,
            'blue_green' => $project->dockerized ? $yes((bool) $project->blue_green) : null,
            'migrate' => $project->dockerized ? [$project->migrate_service ?: 'no', $project->migrate_service ? '' : 't-mute'] : null,
            'backup' => $project->dockerized && $project->migrate_service ? [($project->backup_role ?: 'DB_USERNAME').', keep '.$project->backup_keep, ''] : null,
            'health_url' => [$project->health_url ?: '—', $project->health_url ? '' : 't-mute'],
            'health_cmd' => $project->health_command ? [$project->health_command, ''] : null,
            'timeout' => [$project->health_timeout.'s', ''],
            'auto_rollback' => $yes((bool) $project->auto_rollback),
            'activation' => [$project->activation->value === 'script' ? 'script '.$project->activation_script : $project->activation->value, ''],
        ]);
    @endphp

    <header class="con-prompt">
        <span class="min-w-0 truncate">
            <span class="t-ok">deploy@local</span><span class="t-mute">:</span><span class="t-run">~/projects/{{ $project->slug }}</span>
            <span class="t-mute">$</span> git log --graph {{ $project->branch }}
        </span>
        <nav class="con-keys" aria-label="Keys">
            @if ($targetCount)
                {{-- The dialog works out how to deliver before it shows: the key spins, and
                     ignores more presses, until DeployDialog says it's open. --}}
                <button type="button" class="con-key is-go" data-key="d"
                        x-data="{ opening: false }" x-bind:disabled="opening"
                        x-on:click="if (opening) return; opening = true; setTimeout(() => opening = false, 30000); $wire.dispatch('open-deploy-dialog')"
                        x-on:deploy-dialog-opened.window="opening = false">
                    <kbd>d</kbd> <span x-show="! opening">deploy</span><span x-show="opening" x-cloak>opening…</span>
                </button>
            @else
                <button type="button" class="con-key" disabled title="Assign the project to a server first"><kbd>d</kbd> deploy</button>
            @endif
            @if ($project->hasGitHub())
                <button type="button" class="con-key" data-key="c" wire:click="checkCi" wire:loading.attr="disabled" wire:target="checkCi">
                    <kbd>c</kbd> <span wire:loading.remove wire:target="checkCi">check ci</span><span wire:loading wire:target="checkCi" class="t-run">checking…</span>
                </button>
            @endif
            @if ($project->githubUrl())
                <a href="{{ $project->githubUrl() }}" target="_blank" rel="noopener" class="con-key" data-key="g"><kbd>g</kbd> github ↗</a>
            @endif
        </nav>
    </header>

    <div role="tablist" class="con-tabs" aria-label="Views">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" data-key="{{ $letters[$key] }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-label="{{ $label }}" wire:click="$set('tab', @js($key))">
                {{ $loop->index }}:{{ $names[$key] }}{{ $tab === $key ? '*' : '' }}
                @if ($key === 'targets' && $targetCount)<span class="t-faint">({{ $targetCount }})</span>@endif
                @if ($key === 'environment' && $envCount)<span class="t-faint">({{ $envCount }})</span>@endif
                @if ($key === 'hooks' && $hookCount)<span class="t-faint">({{ $hookCount }})</span>@endif
                <span class="t-faint">· {{ $letters[$key] }}</span>
            </button>
        @endforeach
    </div>

    <div class="con-line pt-2">
        <h1 class="t-b text-[15px]">{{ $project->name }}</h1>
        @if ($ci)
            @if ($ci->hasSignal() && ($project->ci_run['url'] ?? null))
                <a href="{{ $project->ci_run['url'] }}" target="_blank" rel="noopener" class="{{ $ciTone }} hover:underline" title="{{ $project->ci_run['name'] ?? 'Workflow' }} #{{ $project->ci_run['number'] ?? '' }}">ci {{ Str::lower($ci->label()) }}</a>
            @else
                <span class="{{ $ciTone }}">{{ $ci->hasSignal() ? 'ci '.Str::lower($ci->label()) : Str::lower($ci->label()) }}</span>
            @endif
        @endif
        <span class="t-mute">
            {{ $project->sourceLabel() }} · {{ $project->branch }}
            · {{ $project->versionable ? 'releases, keep '.$project->keep_releases : 'in place' }}
            @if ($project->dockerized) · docker stack {{ $project->stack_name }}@endif
        </span>
    </div>

    @if ($green)
        <div role="status" class="con-line t-ok">
            <span>✓ {{ $green['short'] }} passed
                @if ($green['url'])
                    <a href="{{ $green['url'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $green['workflow'] ?? 'CI' }}{{ $green['number'] ? ' #'.$green['number'] : '' }}</a>
                @else
                    {{ $green['workflow'] ?? 'CI' }}
                @endif
                and isn’t live on {{ implode(', ', $green['servers']) }} yet.</span>
            <button type="button" class="con-act"
                    x-data="{ opening: false }" x-bind:disabled="opening"
                    x-on:click="if (opening) return; opening = true; setTimeout(() => opening = false, 30000); $wire.dispatch('open-deploy-dialog', { commit: @js($green['sha']) })"
                    x-on:deploy-dialog-opened.window="opening = false">
                <span x-show="! opening">Deploy {{ $green['short'] }}</span><span x-show="opening" x-cloak>opening…</span>
            </button>
        </div>
    @endif
    @if ($notice)
        <div role="status" class="con-line t-ok">✓ {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="con-line t-err">✕ {{ $problem }}</div>
    @endif

    @if ($tab === 'targets')
        <div class="flex flex-wrap items-start gap-[22px_14px] px-[14px] pt-[22px] pb-4">
            <section class="con-box px-0 grow-[999] basis-[560px] min-w-0" aria-label="Commits and releases">
                <span class="con-box-t"><b>{{ $project->branch }}</b> · {{ $project->githubFullName() ?? \App\Support\Format::homePath($project->local_path) }}@if ($ci?->hasSignal()) · <span class="{{ $ciTone }}">ci {{ $ci === CiState::Passing ? '✓' : ($ci === CiState::Failing ? '✕' : '●') }}</span>@endif</span>
                <livewire:console.git :arg="$project->slug" :page="true" :key="'git-'.$project->id" defer />
            </section>

            <div class="grow basis-[320px] min-w-[min(280px,100%)] flex flex-col gap-[22px]">
                <section class="con-box" aria-label="Targets">
                    <span class="con-box-t"><b>targets</b> · {{ $targetCount }}</span>
                    @forelse ($targetList as $target)
                        @php $run = $target->activeDeployment; @endphp
                        <div class="flex flex-col py-1 border-b border-dashed border-line last:border-b-0" wire:key="side-target-{{ $target->id }}">
                            <span class="flex gap-2">
                                <span class="{{ $run ? 't-run' : $healthTone($target->health) }}">●</span>
                                <a href="{{ route('servers.show', $target->server) }}" wire:navigate class="t-b font-semibold hover:underline">{{ $target->server->name }}</a>
                                @if ($run)
                                    <a href="{{ route('deployments.show', $run) }}" wire:navigate class="ml-auto t-run hover:underline">{{ $run->number() }} {{ Str::lower($run->status->label()) }}</a>
                                @else
                                    <span class="ml-auto {{ $healthTone($target->health) }}" title="{{ $target->health_detail }}">{{ Str::lower($target->health->label()) }}</span>
                                @endif
                            </span>
                            <span class="t-mute break-all">{{ $target->path }} <span class="t-faint"># {{ $target->folderNote() }}</span>{{ $target->currentRelease ? ' · '.($target->currentRelease->shortSha() ?? $target->currentRelease->name) : ' · nothing live yet' }}</span>
                        </div>
                    @empty
                        <p class="t-mute">Not on a server yet: assign it below.</p>
                    @endforelse
                </section>

                <section class="con-box" aria-label="Deploy settings">
                    <span class="con-box-t"><b>deploy.conf</b> · <button type="button" wire:click="$set('tab', 'settings')">, edit</button></span>
                    <dl class="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-2 leading-[1.75]">
                        @foreach ($conf as $key => [$value, $tone])
                            <dt class="t-v">{{ $key }} <span class="t-faint">=</span></dt>
                            <dd class="{{ $tone }} break-words">{{ $value }}</dd>
                        @endforeach
                    </dl>
                </section>
            </div>

            <section class="con-box basis-full min-w-0" aria-label="Targets and their actions">
                <span class="con-box-t"><b>targets</b> · assign, prepare, deploy, roll back</span>
                <div class="flex flex-col gap-4">
                    <livewire:projects.targets :project-id="$project->id" :key="'targets-'.$project->id" />
                </div>
            </section>

            <div class="basis-full min-w-0 pt-2">
                @include('partials.trail', ['entries' => $trail, 'link' => route('audit', ['project' => $project->id]), 'class' => ''])
            </div>
        </div>
    @endif

    @if (in_array($tab, ['releases', 'environment', 'hooks', 'settings'], true))
        <div class="flex flex-col gap-4 px-[14px] py-4">
            @if ($tab === 'releases')
                <livewire:projects.releases :project-id="$project->id" :key="'releases-'.$project->id" />
            @elseif ($tab === 'environment')
                <livewire:projects.environment :project-id="$project->id" :key="'environment-'.$project->id" />
            @elseif ($tab === 'hooks')
                <livewire:projects.hooks :project-id="$project->id" :key="'hooks-'.$project->id" />
            @else
                @include('livewire.projects.partials.settings')
            @endif
        </div>
    @endif

    <livewire:projects.deploy-dialog :project-id="$project->id" :key="'deploy-'.$project->id" />
</div>
