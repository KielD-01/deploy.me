<div class="flex flex-col gap-5" @if ($reading) wire:poll.3s @endif>
    @php
        use App\Deploy\Conversion\Conversion;
        use App\Deploy\Conversion\ConversionPlanner;
        use App\Support\Format;
        use Illuminate\Support\Carbon;
        $stack = $plan['stack'] ?? null;
        $commit = $plan['commit'] ?? null;
        $failed = collect($plan['checks'] ?? [])->where('state', 'fail')->count();
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('projects.index') }}" wire:navigate class="hover:text-base-content">Projects</a>
            <x-icon name="chevron-right" :size="12" />
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:text-base-content">{{ $project->name }}</a>
            <x-icon name="chevron-right" :size="12" /> <span>Convert on {{ $server->name }}</span>
        </div>
        <div class="flex items-end gap-4">
            <div class="flex flex-col gap-1 grow min-w-0">
                <h1 class="text-[22px] leading-7 font-semibold tracking-tight">Convert to releases</h1>
                <p class="text-muted">
                    {{ $project->name }} on {{ $server->name }} · <span class="mono">{{ $target->path }}</span>
                    @if ($reading)
                        · reading the folder…
                    @elseif (isset($scan['read_at']))
                        · read {{ Carbon::parse($scan['read_at'])->diffForHumans() }}
                    @endif
                    · nothing on the server has changed yet
                </p>
            </div>
            <button type="button" class="btn btn-sm" wire:click="readAgain" @disabled($reading)>
                <x-icon name="refresh" :size="14" @class(['animate-spin' => $reading]) /> Read the folder again
            </button>
        </div>
    </div>

    <ol class="flex items-center gap-3 text-[13px] font-medium text-muted">
        <li class="flex items-center gap-2">
            <span @class(['size-[22px] rounded-full grid place-items-center border text-xs', 'bg-success/10 border-success/30 text-success' => $plan, 'border-line' => ! $plan])>
                @if ($plan) <x-icon name="check" :size="12" /> @else 1 @endif
            </span>
            <span @class(['text-base-content' => ! $plan])>Read the folder</span>
        </li>
        <li class="w-8 h-px bg-line" aria-hidden="true"></li>
        <li class="flex items-center gap-2">
            <span @class(['size-[22px] rounded-full grid place-items-center border text-xs', 'bg-primary border-primary text-primary-content' => $plan, 'border-line' => ! $plan])>2</span>
            <span @class(['text-base-content' => $plan])>Review the plan</span>
        </li>
        <li class="w-8 h-px bg-line" aria-hidden="true"></li>
        <li class="flex items-center gap-2">
            <span class="size-[22px] rounded-full grid place-items-center border border-line text-xs">3</span> Convert
        </li>
    </ol>

    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @if (! $plan)
        <section class="card bg-base-100 border border-line">
            <div class="p-6 flex items-center gap-3 text-[13px]">
                @if ($reading)
                    <x-icon name="loader" class="animate-spin text-info" /> Reading {{ $target->path }} on {{ $server->name }}. This changes nothing there.
                @elseif ($scan['error'] ?? null)
                    <x-icon name="x-circle" class="text-error" /> {{ $scan['error'] }}
                @elseif (isset($scan['items']))
                    <x-icon name="check-circle" class="text-success" /> {{ $target->path }} holds nothing but deploy.me’s own folders, so there’s nothing to convert.
                @else
                    <x-icon name="pending" class="text-muted" /> The folder hasn’t been read yet.
                @endif
            </div>
        </section>
    @else
        @if ($ci)
            <div role="alert" class="alert alert-soft alert-error text-[13px] items-start">
                <x-icon name="x-circle" class="mt-0.5" />
                <div class="flex flex-col gap-1">
                    <span class="font-semibold">GitHub Actions deploys into this folder</span>
                    <span>
                        {{ ($ci['automatic'] ?? false) ? 'On every push, ' : '' }}<span class="mono">{{ basename($ci['workflow']) }}</span> deploys into {{ $target->path }}{{ ($ci['script'] ?? null) ? ' and runs '.$ci['script'].' there' : '' }}.
                        After converting, that would put the old layout back. Remove its deploy job (deploy.me takes over from here), then read the folder again.
                    </span>
                </div>
            </div>
        @endif

        @if ($scan['error'] ?? null)
            <div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> The last read failed: {{ $scan['error'] }} The plan is from the read before it.</div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)] gap-4 items-start">
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">What goes where</h2>
                    <span class="text-[13px] text-muted">{{ $plan['items'] }} {{ Str::plural('item', $plan['items']) }} · {{ Format::bytes($plan['bytes']) }}</span>
                </header>

                @if ($commit && $commit['how'] === 'compared')
                    <div class="flex items-start gap-2.5 px-4 py-3 border-b border-line bg-success/5 text-[13px]">
                        <x-icon name="check-circle" class="text-success mt-0.5 shrink-0" />
                        <span>Apart from what’s only on the server, every file matches commit <span class="mono font-semibold">{{ substr($commit['sha'], 0, 7) }}</span>{{ $commit['message'] ? ' “'.$commit['message'].'”' : '' }}, checked one by one. That commit becomes the first release.</span>
                    </div>
                @elseif ($commit && $commit['how'] === 'git')
                    <div class="flex items-start gap-2.5 px-4 py-3 border-b border-line bg-success/5 text-[13px]">
                        <x-icon name="check-circle" class="text-success mt-0.5 shrink-0" />
                        <span>A git checkout at <span class="mono font-semibold">{{ substr($commit['sha'], 0, 7) }}</span>{{ $commit['dirty'] ? ', with '.$commit['dirty'].' changed '.Str::plural('file', $commit['dirty']) : '' }}. That commit becomes the first release.</span>
                    </div>
                @elseif ($commit && $commit['how'] === 'image')
                    <div class="flex items-start gap-2.5 px-4 py-3 border-b border-line bg-sunk text-[13px]">
                        <x-icon name="info" class="text-muted mt-0.5 shrink-0" />
                        <span>The stack runs the image tagged <span class="mono">{{ substr($commit['sha'], 0, 7) }}</span> in .env, so that commit becomes the first release.</span>
                    </div>
                @elseif ($plan['closest'])
                    <div class="flex items-start gap-2.5 px-4 py-3 border-b border-line bg-warning/5 text-[13px]">
                        <x-icon name="alert" class="text-warning mt-0.5 shrink-0" />
                        <span>No commit matches exactly: the closest is <span class="mono">{{ substr($plan['closest']['sha'], 0, 7) }}</span>, with {{ $plan['closest']['differs'] }} {{ Str::plural('file', $plan['closest']['differs']) }} that differ. The first release won’t name a commit.</span>
                    </div>
                @elseif ($plan['comparison_error'])
                    <div class="flex items-start gap-2.5 px-4 py-3 border-b border-line bg-sunk text-[13px]">
                        <x-icon name="info" class="text-muted mt-0.5 shrink-0" /> <span>{{ $plan['comparison_error'] }}</span>
                    </div>
                @endif

                <table class="table">
                    <thead><tr><th>Item</th><th class="text-right">Size</th><th>Why</th><th>Goes to</th></tr></thead>
                    <tbody>
                        @foreach ($plan['rows'] as $row)
                            <tr wire:key="row-{{ md5($row['path']) }}" @class(['bg-warning/5' => $row['call'] && $row['to'] === Conversion::STAYS])>
                                <td>
                                    <div @class(['flex items-center gap-2 min-w-0', 'pl-6' => $row['nested']])>
                                        <x-icon :name="$row['nested'] ? 'arrow-right' : ($row['type'] === 'd' ? 'folder' : ($row['type'] === 'l' ? 'link' : 'file'))" :size="14" class="text-muted shrink-0" />
                                        <span class="mono truncate">{{ $row['path'] }}{{ $row['type'] === 'd' && ! $row['nested'] ? '/' : '' }}</span>
                                    </div>
                                </td>
                                <td class="text-right"><span class="mono text-muted whitespace-nowrap">{{ Format::bytes($row['bytes']) }}</span></td>
                                <td class="text-[13px] text-muted">
                                    @if ($row['call'] && $row['to'] === Conversion::STAYS)
                                        <x-icon name="alert" :size="12" class="text-warning" />
                                    @endif
                                    {{ $row['why'] }}
                                    @unless ($row['same_fs'])
                                        <span class="text-error">· on another filesystem</span>
                                    @endunless
                                </td>
                                <td>
                                    <div class="join">
                                        @foreach ($row['options'] as $to)
                                            <button type="button" wire:click="pick({{ Js::from($row['path']) }}, '{{ $to }}')" aria-pressed="{{ $row['to'] === $to ? 'true' : 'false' }}"
                                                    @class(['btn btn-xs join-item', 'btn-primary' => $row['to'] === $to])>{{ ucfirst($to) }}</button>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <footer class="px-4 py-3 border-t border-line text-xs text-muted">
                    deploy.me’s own .deploy/, releases/ and shared/ aren’t part of the plan. Anything marked Stays is left exactly where it is.
                </footer>
            </section>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">After converting</h2>
                    </header>
                    <div class="p-4 flex flex-col gap-3">
                        @php
                            $names = $intoRelease->map(fn ($row) => $row['path'].($row['type'] === 'd' ? '/' : ''))->all();
                            $inner = [[implode('  ', array_slice($names, 0, 4)).(count($names) > 4 ? '  …' : ''), '']];
                            foreach ($intoShared as $row) {
                                $inner[] = [$row['path'], '→ shared'];
                            }
                            $lines = [
                                [$target->path, ''],
                                ['├─ current', '→ releases/'.$releaseName],
                                ['├─ releases/', ''],
                                ['│  └─ '.$releaseName.'/', $commit ? substr($commit['sha'], 0, 7) : ''],
                            ];
                            foreach ($inner as $i => [$text, $note]) {
                                $lines[] = ['│     '.($i === count($inner) - 1 ? '└─ ' : '├─ ').$text, $note];
                            }
                            $lines[] = ['├─ shared/', $intoShared->isEmpty() ? 'empty' : $intoShared->pluck('path')->implode(' · ')];
                            foreach ($staying as $row) {
                                $lines[] = ['├─ '.$row['path'].($row['type'] === 'd' ? '/' : ''), 'stays'];
                            }
                            $lines[] = ['└─ .deploy/', 'the old layout, as hard links'];
                        @endphp
                        <div class="rounded-box bg-sunk p-3 font-mono text-[12px] leading-[18px] whitespace-pre overflow-x-auto">{!! collect($lines)->map(fn ($line) => e($line[0]).($line[1] !== '' ? '  <span class="text-muted">'.e($line[1]).'</span>' : ''))->implode("\n") !!}</div>
                        @if ($intoShared->isNotEmpty())
                            <p class="text-[13px]">
                                From then on, every release links {{ ConversionPlanner::sentence($intoShared->pluck('path')->all()) }} from shared/.
                            </p>
                        @endif
                    </div>
                </section>

                @if ($stack)
                    <section class="card bg-base-100 border border-line">
                        <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                            <h2 class="text-[15px] font-semibold">The running stack</h2>
                            <span class="mono text-[13px] text-muted">{{ $stack['name'] }}</span>
                        </header>
                        <div class="p-4 flex flex-col gap-2">
                            <button type="button" wire:click="$set('restart', false)" @class(['flex items-start gap-3 rounded-box border p-3 text-left', 'border-primary bg-primary/5' => ! $restart, 'border-line hover:bg-base-200/60' => $restart])>
                                <input type="radio" class="radio radio-sm mt-0.5" @checked(! $restart) tabindex="-1" aria-hidden="true">
                                <span class="flex flex-col gap-0.5">
                                    <span class="flex items-center gap-2 font-semibold">Leave it running <span class="badge badge-sm badge-soft badge-success">Recommended</span></span>
                                    <span class="text-xs text-muted">
                                        @if (($stack['containers'] ?? null) === 0)
                                            Nothing of the stack is running right now. The next deploy starts it from releases/.
                                        @elseif ($stack['binds'] === [])
                                            No container reads this folder: there are no bind mounts{{ $stack['volumes'] !== [] ? ', and the data lives in the volumes '.ConversionPlanner::sentence($stack['volumes']) : '' }}. The next deploy recreates the stack from releases/.
                                        @else
                                            Its containers keep the folders they mounted, even after the move. The next deploy recreates them from releases/.
                                        @endif
                                    </span>
                                </span>
                            </button>
                            <button type="button" wire:click="$set('restart', true)" @class(['flex items-start gap-3 rounded-box border p-3 text-left', 'border-primary bg-primary/5' => $restart, 'border-line hover:bg-base-200/60' => ! $restart])>
                                <input type="radio" class="radio radio-sm mt-0.5" @checked($restart) tabindex="-1" aria-hidden="true">
                                <span class="flex flex-col gap-0.5">
                                    <span class="font-semibold">Restart it from the new folder</span>
                                    <span class="text-xs text-muted">
                                        Recreates {{ $stack['services'] !== [] ? ConversionPlanner::sentence($stack['services']) : 'its services' }} from the release, then runs the health check. A few seconds of downtime.
                                    </span>
                                </span>
                            </button>
                        </div>
                    </section>
                @endif

                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Checks</h2>
                        <span @class(['text-[13px]', 'text-muted' => $failed === 0, 'text-error' => $failed > 0])>{{ $failed === 0 ? 'nothing blocks it' : $failed.' failed' }}</span>
                    </header>
                    <ul class="flex flex-col">
                        @foreach ($plan['checks'] as $check)
                            <li wire:key="check-{{ $loop->index }}" @class(['flex items-start gap-3 px-4 py-3 border-b border-line last:border-b-0 text-[13px]', 'bg-error/5' => $check['state'] === 'fail'])>
                                <x-icon :name="match ($check['state']) { 'ok' => 'check-circle', 'warn' => 'alert', default => 'x-circle' }"
                                        @class(['mt-0.5 shrink-0', 'text-success' => $check['state'] === 'ok', 'text-warning' => $check['state'] === 'warn', 'text-error' => $check['state'] === 'fail']) />
                                <span class="grow flex flex-col gap-1 min-w-0">
                                    <span>{{ $check['text'] }}</span>
                                    @foreach ($check['lines'] ?? [] as $line)
                                        <span class="mono text-xs text-muted break-all">{{ $line }}</span>
                                    @endforeach
                                </span>
                                @isset($check['workflow'])
                                    <span @class(['mono text-xs', 'text-error' => $check['state'] === 'fail', 'text-muted' => $check['state'] !== 'fail'])>{{ basename($check['workflow']) }}</span>
                                @endisset
                            </li>
                        @endforeach
                    </ul>
                </section>

                <div class="flex flex-col gap-2">
                    <button type="button" class="btn btn-primary w-full" wire:click="convert" wire:loading.attr="disabled" wire:target="convert" @disabled(! $ready || $reading)
                            wire:confirm="Convert {{ $target->path }} on {{ $server->name }}? {{ $plan['to_release'] }} {{ Str::plural('item', $plan['to_release']) }} move into a new release{{ $plan['to_shared'] ? ' and '.$plan['to_shared'].' into shared/' : '' }}. Nothing is deleted.">
                        <x-icon name="layers" /> Convert {{ $target->path }}
                    </button>
                    <span class="text-xs text-muted text-center">{{ $ready ? 'Nothing is deleted. Until the next deploy, Undo moves every item back.' : 'Fix the failed checks first.' }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
