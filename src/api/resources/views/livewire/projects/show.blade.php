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
        @include('livewire.projects.partials.settings')
    @endif

    <livewire:projects.deploy-dialog :project-id="$project->id" :key="'deploy-'.$project->id" />
</div>
