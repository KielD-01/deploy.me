<div class="flex flex-col gap-5">
    <x-page-header title="Settings" />

    <div role="tablist" class="tabs tabs-border">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', @js($key))" @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px] max-w-4xl"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px] max-w-4xl"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @if ($tab === 'schedules')
        <form wire:submit="saveSchedules" class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Schedules</h2>
                <span class="text-[13px] text-muted">Server probes are set on each server’s Settings tab.</span>
            </header>
            <div class="p-4 grid grid-cols-2 gap-4">
                @foreach ([
                    'health_interval' => ['Health checks', 'Each deployed target’s health URL and Docker services, asked from its server.'],
                    'ci_poll_interval' => ['CI status', 'GitHub Actions on each project’s branch. A deploy waiting for CI checks every 20 s.'],
                    'log_retention_days' => ['Deploy logs', 'How long a run’s log stays in the database. Then it’s gzipped into an archive.'],
                    'archive_retention_days' => ['Log archives', 'Still downloadable from each run.'],
                    'snapshot_retention_days' => ['Probe history', 'The CPU, memory and disk readings behind the charts.'],
                    'health_retention_days' => ['Health history', 'Each target’s checks, on the server’s Checks tab.'],
                    'audit_retention_days' => ['Audit log', 'Who did what, and every command run on a server.'],
                ] as $key => [$label, $hint])
                    <label class="flex flex-col gap-1.5" wire:key="schedule-{{ $key }}">
                        <span class="text-[13px] font-medium">{{ $label }}</span>
                        <select wire:model="schedules.{{ $key }}" class="select select-sm">
                            @foreach ($choices[$key] as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-muted">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
            <footer class="flex justify-end px-4 py-3 border-t border-line">
                <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" :size="14" /> Save schedules</button>
            </footer>
        </form>
    @endif

    @if ($tab === 'github')
        <section class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">GitHub tokens</h2>
                <span class="text-[13px] text-muted">Importing repositories, pushing, CI status and GHCR images</span>
                @unless ($adding)
                    <button type="button" class="btn btn-sm ml-auto" wire:click="startAddingToken"><x-icon name="plus" :size="14" /> Add token</button>
                @endunless
            </header>

            @if ($adding)
                <form wire:submit="addToken" class="p-4 flex flex-col gap-2 border-b border-line bg-base-200/50">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Personal access token</span>
                        <div class="flex gap-2">
                            <input type="password" wire:model="token" autocomplete="off" spellcheck="false" placeholder="ghp_…" class="input grow font-mono" autofocus>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addToken">
                                <x-icon name="check" />
                                <span wire:loading.remove wire:target="addToken">Check and save</span>
                                <span wire:loading wire:target="addToken">Checking with GitHub…</span>
                            </button>
                            <button type="button" class="btn btn-ghost" wire:click="$set('adding', false)">Cancel</button>
                        </div>
                    </label>
                    <p class="text-xs text-muted">
                        Create one on GitHub under Settings → Developer settings → Personal access tokens → Tokens (classic),
                        with <span class="mono">repo</span> and <span class="mono">read:packages</span>. It’s stored encrypted and only sent to
                        api.github.com and to git over HTTPS.
                    </p>
                </form>
            @endif

            @if ($credentials->isEmpty())
                <p class="px-4 py-6 text-[13px] text-muted">No tokens yet. Without one, deploy.me can import local folders, but can’t read GitHub, push, or show CI.</p>
            @else
                <table class="table">
                    <thead><tr><th>Account</th><th>Token</th><th>Scopes</th><th>Expires</th><th>Projects</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($credentials as $credential)
                            @php $others = array_values(array_filter($credential->owners ?? [], fn ($owner) => strcasecmp($owner, $credential->login) !== 0)); @endphp
                            <tr wire:key="credential-{{ $credential->id }}">
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-semibold">{{ $credential->login }}</span>
                                        @if ($others)
                                            <span class="text-xs text-muted">Also {{ implode(', ', $others) }}</span>
                                        @endif
                                        @if ($credential->last_error)
                                            <span class="text-xs text-error">{{ $credential->last_error }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="mono text-muted whitespace-nowrap">{{ $credential->token_hint }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($credential->scopes ?? [] as $scope)
                                            <span class="badge badge-sm badge-ghost font-mono">{{ $scope }}</span>
                                        @empty
                                            <span class="text-xs text-muted">{{ $credential->token_type === 'fine_grained' ? 'Fine-grained' : '—' }}</span>
                                        @endforelse
                                    </div>
                                    @if ($credential->token_type === 'classic' && ! $credential->hasScope('read:packages'))
                                        <span class="text-xs text-warning">Without read:packages, GHCR images can’t be pulled</span>
                                    @endif
                                </td>
                                <td @class(['text-[13px] whitespace-nowrap', 'text-error' => $credential->expires_at?->isPast()])>{{ $credential->expires_at?->toFormattedDateString() ?? 'Never' }}</td>
                                <td class="text-[13px] text-muted">{{ $credential->projects_count }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <button type="button" class="btn btn-xs btn-ghost" wire:click="checkToken({{ $credential->id }})" wire:loading.attr="disabled">Check</button>
                                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="removeToken({{ $credential->id }})"
                                            wire:confirm="Remove {{ $credential->login }}’s token? Projects that use it lose CI status and pushes until you add another.">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <footer class="px-4 py-3 border-t border-line text-xs text-muted inline-flex items-center gap-1.5">
                <x-icon name="info" :size="12" /> Use classic tokens with repo and read:packages. Fine-grained tokens can’t read GHCR packages yet.
            </footer>
        </section>
    @endif

    @if ($tab === 'ssh')
        <section class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">SSH</h2>
                <span class="text-[13px] text-muted">How deploy.me reaches your servers</span>
            </header>
            <dl class="p-4 grid grid-cols-[140px_minmax(0,1fr)] gap-y-2.5 gap-x-4 text-[13px]">
                <dt class="text-muted">Config</dt>
                <dd>~/.ssh/config · {{ $ssh['servers'] + $ssh['git'] }} hosts: {{ $ssh['servers'] }} {{ Str::plural('server', $ssh['servers']) }}, {{ $ssh['git'] }} git {{ Str::plural('identity', $ssh['git']) }}</dd>
                <dt class="text-muted">Keys</dt>
                <dd>The key files in ~/.ssh, mounted read-only</dd>
                <dt class="text-muted">ssh-agent</dt>
                <dd>{{ $ssh['agent'] ? 'Forwarded from your Mac' : 'Not available; key files are used directly' }}</dd>
                <dt class="text-muted">Known hosts</dt>
                <dd>{{ $ssh['trusted'] }} {{ Str::plural('server', $ssh['trusted']) }} trusted in deploy.me, plus your ~/.ssh/known_hosts</dd>
                <dt class="text-muted">Compiled config</dt>
                <dd><span class="mono">{{ $ssh['path'] }}</span> · {{ $ssh['compiledAt'] ? 'built '.\Illuminate\Support\Carbon::createFromTimestamp($ssh['compiledAt'])->diffForHumans() : 'built on the first connection' }}</dd>
            </dl>
        </section>
    @endif

    @if ($tab === 'alerts')
        <form wire:submit="saveAlerts" class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Alerts</h2>
                <span class="text-[13px] text-muted">A Telegram message when a run fails or a site stops answering.</span>
            </header>
            <div class="p-4 flex flex-col gap-4">
                <label class="flex flex-col gap-1.5 max-w-md">
                    <span class="text-[13px] font-medium">Bot token</span>
                    <input type="password" wire:model="telegramToken" autocomplete="off" spellcheck="false" class="input input-sm font-mono"
                           placeholder="{{ $telegramSet ? 'stored — type a new one to replace it' : '123456789:AA…' }}">
                    <span class="text-xs text-muted">Create a bot with {{ '@BotFather' }} in Telegram; it sends the token when the bot is made. Stored encrypted, and never shown again.</span>
                    @error('telegramToken')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5 max-w-md">
                    <span class="text-[13px] font-medium">Chat</span>
                    <input type="text" wire:model="telegramChatId" spellcheck="false" class="input input-sm font-mono" placeholder="123456789">
                    <span class="text-xs text-muted">Your own chat id (ask {{ '@userinfobot' }}), or {{ '@channel' }} for a channel the bot posts to. Write to the bot once first: Telegram won't let it open the conversation.</span>
                    @error('telegramChatId')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" :size="14" /> Save alerts</button>
                    <button type="button" class="btn btn-sm" wire:click="sendTestAlert" wire:loading.attr="disabled" wire:target="sendTestAlert">Send a test message</button>
                    @if ($telegramSet)
                        <button type="button" class="btn btn-sm btn-ghost ml-auto" wire:click="forgetAlerts">Switch alerts off</button>
                    @endif
                </div>
                <p class="text-xs text-muted">Sent when a run fails, rolls back or stalls, and when a target stops answering or answers again. Nothing else, and a message that can't be sent never fails the deploy.</p>
            </div>
        </form>
    @endif

    @if ($tab === 'account')
        <section class="card bg-base-100 border border-line max-w-3xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Account</h2>
            </header>
            <div class="p-4 flex items-center gap-3">
                <span class="size-[30px] rounded-full bg-sunk text-muted grid place-items-center"><x-icon name="user" /></span>
                <div class="flex flex-col grow min-w-0">
                    <span class="font-semibold truncate">{{ $user->name }}</span>
                    <span class="text-xs text-muted truncate">{{ $user->email }}</span>
                </div>
                <button type="submit" form="logout-form" class="btn btn-sm"><x-icon name="logout" :size="14" /> Sign out</button>
            </div>
        </section>

        <section class="card bg-base-100 border border-line max-w-3xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Passkeys</h2>
                <span class="text-[13px] text-muted">Touch ID, Windows Hello, a phone, a security key</span>
            </header>

            @if ($enroll && $passkeys->isEmpty() && ! $user->hasPassword())
                <div class="p-4 pb-0">
                    <div role="alert" class="alert alert-soft alert-info text-[13px]">
                        <x-icon name="info" />
                        You’re signed in with a one-time link. Register a passkey now, or set a password below if this device can’t use passkeys: it’s how you’ll sign in from here on.
                    </div>
                </div>
            @endif

            <div class="flex flex-col">
                @forelse ($passkeys as $passkey)
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-line" wire:key="passkey-{{ $passkey->id }}">
                        <x-icon name="fingerprint" class="text-muted" />
                        <div class="flex flex-col grow min-w-0">
                            <span class="font-semibold truncate">{{ $passkey->name }}</span>
                            <span class="text-xs text-muted">
                                {{ $passkey->authenticator ?? 'Passkey' }} · added {{ $passkey->created_at->forViewer()->toFormattedDateString() }}
                                · {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'not used yet' }}
                            </span>
                        </div>
                        @if ($passkeys->count() > 1 || $user->hasPassword())
                            <button type="button" class="btn btn-xs btn-ghost text-error"
                                    x-data="passkeyRemove(@js(route('passkey.destroy', $passkey)), @js($passkey->name))"
                                    x-on:click="remove" x-bind:disabled="busy">
                                <x-icon name="trash" :size="12" /> Remove
                            </button>
                        @else
                            <span class="text-xs text-muted" title="Set a password or add another passkey first">Your only way in</span>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-muted border-b border-line">No passkeys yet.</p>
                @endforelse
            </div>

            <form class="p-4 flex flex-col gap-2" x-data="passkeyRegister" x-on:submit.prevent="register">
                <div class="flex items-end gap-2">
                    <label class="flex flex-col gap-1.5 grow">
                        <span class="text-[13px] font-medium">Add a passkey on this device</span>
                        <input type="text" x-model="name" placeholder="MacBook Pro" maxlength="100" class="input w-full">
                    </label>
                    <button type="submit" class="btn btn-primary" x-bind:disabled="busy">
                        <x-icon name="plus" /> <span x-text="busy ? 'Waiting for your passkey…' : 'Add passkey'">Add passkey</span>
                    </button>
                </div>
                <p class="text-error text-[13px]" x-show="error" x-text="error" x-cloak></p>
            </form>
        </section>

        <section class="card bg-base-100 border border-line max-w-3xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Password</h2>
                <span class="text-[13px] text-muted">For devices that can’t use a passkey</span>
                @if ($user->hasPassword())
                    <span class="badge badge-soft badge-success badge-sm ml-auto">Set {{ $user->password_set_at->forViewer()->toFormattedDateString() }}</span>
                @else
                    <span class="badge badge-ghost badge-sm ml-auto">Not set</span>
                @endif
            </header>
            <form class="p-4 flex flex-col gap-3" wire:submit="savePassword">
                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">{{ $user->hasPassword() ? 'New password' : 'Password' }}</span>
                        <input type="password" wire:model="password" autocomplete="new-password" minlength="12" class="input w-full">
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Repeat it</span>
                        <input type="password" wire:model="passwordConfirmation" autocomplete="new-password" minlength="12" class="input w-full">
                    </label>
                </div>
                <p class="text-xs text-muted">At least 12 characters. You sign in with {{ $user->email }} and this password; a passkey keeps working too.</p>
                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="key" :size="14" /> {{ $user->hasPassword() ? 'Change password' : 'Set password' }}</button>
                    @if ($user->hasPassword())
                        @if ($passkeys->isNotEmpty())
                            <button type="button" class="btn btn-ghost btn-sm text-error" wire:click="removePassword" wire:confirm="Remove the password? You’ll sign in with a passkey only.">Remove password</button>
                        @else
                            <span class="text-xs text-muted">Your only way in: add a passkey before removing it.</span>
                        @endif
                    @endif
                </div>
            </form>
        </section>

        @if ($user->hasPassword())
            <section class="card bg-base-100 border border-line max-w-3xl">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] leading-[22px] font-semibold">Authenticator codes</h2>
                    <span class="text-[13px] text-muted">A code from an app on top of the password</span>
                    @if ($user->hasTwoFactor())
                        <span class="badge badge-soft badge-success badge-sm ml-auto">On</span>
                    @else
                        <span class="badge badge-ghost badge-sm ml-auto">Off</span>
                    @endif
                </header>
                <div class="p-4 flex flex-col gap-3">
                    @if ($user->hasTwoFactor())
                        <p class="text-[13px]">Signing in with the password asks for a code from your authenticator app. Passkeys don’t need one.</p>
                        @if ($recoveryCodes !== [])
                            <div class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium">Recovery codes: each works once, if you lose the app</span>
                                <div class="grid grid-cols-2 gap-1 font-mono text-[13px] bg-sunk rounded-box p-3">
                                    @foreach ($recoveryCodes as $code)
                                        <span>{{ $code }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn btn-sm" wire:click="newRecoveryCodes">New recovery codes</button>
                            <button type="button" class="btn btn-ghost btn-sm text-error" wire:click="disableTwoFactor" wire:confirm="Turn off authenticator codes?">Turn off</button>
                        </div>
                    @elseif ($user->two_factor_secret)
                        <p class="text-[13px]">Scan this with Google Authenticator, 1Password, Aegis or any authenticator app, then enter the code it shows.</p>
                        <div class="flex flex-wrap items-start gap-4">
                            <div class="bg-white p-2 rounded-box border border-line">{!! $user->twoFactorQrCodeSvg() !!}</div>
                            <div class="flex flex-col gap-2 grow min-w-[200px]">
                                <span class="text-xs text-muted">Or type this key in: <span class="font-mono select-all">{{ decrypt($user->two_factor_secret) }}</span></span>
                                <form class="flex items-end gap-2" wire:submit="confirmTwoFactor">
                                    <label class="flex flex-col gap-1.5 grow">
                                        <span class="text-[13px] font-medium">Code</span>
                                        <input type="text" wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="input w-full font-mono tracking-widest">
                                    </label>
                                    <button type="submit" class="btn btn-primary">Turn on</button>
                                </form>
                                <button type="button" class="link text-[13px] self-start" wire:click="disableTwoFactor">Cancel</button>
                            </div>
                        </div>
                    @else
                        <p class="text-[13px]">Optional. With it on, signing in with the password also asks for a 6-digit code from an app on your phone.</p>
                        <div><button type="button" class="btn btn-sm" wire:click="enableTwoFactor"><x-icon name="shield" :size="14" /> Set up authenticator codes</button></div>
                    @endif
                </div>
            </section>
        @endif
    @endif
</div>
