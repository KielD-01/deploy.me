{{-- A server entered by hand: the host, user, port, key and jump host deploy.me keeps
     for it, and a test of the connection. The Console theme has its own ssh.conf. --}}
<form wire:submit="saveConnection" class="card bg-base-100 border border-line max-w-3xl" aria-label="SSH connection">
    <header class="flex flex-wrap items-center gap-x-2.5 gap-y-1 min-h-[52px] px-4 py-2 border-b border-line">
        <h2 class="text-[15px] font-semibold">SSH connection</h2>
        <span class="badge badge-sm badge-soft badge-primary">Entered by hand</span>
        <span class="text-[13px] text-muted">kept in deploy.me’s own ssh config as <span class="mono">Host {{ $server->ssh_alias }}</span></span>
        @if ($connectionNotice)<span class="text-[13px] text-success inline-flex items-center gap-1"><x-icon name="check" :size="12" /> {{ $connectionNotice }}</span>@endif
    </header>

    <div class="p-4 grid grid-cols-1 sm:grid-cols-6 gap-x-3 gap-y-4">
        @include('livewire.servers.partials.manual-fields', ['aliasLocked' => true, 'choices' => $jumpChoices])
    </div>

    @if ($connectionTest)
        <div class="mx-4 mb-4 rounded-box bg-sunk p-3 font-mono text-[12px] leading-5 flex flex-col gap-1" role="status">
            <span class="font-medium">Test connection · ssh {{ $server->ssh_alias }} true</span>
            <span><span @class(['text-success' => $connectionTest['ok'], 'text-error' => ! $connectionTest['ok']])>{{ $connectionTest['ok'] ? '✓' : '✕' }}</span> {{ $connectionTest['detail'] }}</span>
            @if ($connectionTest['ssh'])<span class="text-muted break-all">{{ $connectionTest['ssh'] }}</span>@endif
        </div>
    @endif

    @if ($connectionHostKey)
        <div class="mx-4 mb-4 flex flex-col gap-2 rounded-box border border-warning/40 bg-warning/10 p-3">
            <p class="text-[13px]">Compare the fingerprint with <span class="mono whitespace-nowrap">ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub</span> on the server before you trust it.</p>
            @foreach ($connectionHostKey['keys'] as $key)
                <div class="flex items-center gap-3 px-3 py-2.5 rounded-md border border-warning/40 bg-base-100">
                    <span class="badge badge-sm badge-outline">{{ $key['type'] }}</span>
                    <span class="mono grow break-all">{{ $key['fingerprint'] }}</span>
                </div>
            @endforeach
            <div><button type="button" class="btn btn-sm btn-primary" wire:click="trustConnectionKey" wire:loading.attr="disabled" wire:target="trustConnectionKey"><x-icon name="shield" :size="14" /> Trust and test again</button></div>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-t border-line">
        <button type="button" class="btn btn-sm" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection,saveConnection,trustConnectionKey">
            <x-icon name="terminal" :size="14" wire:loading.class="animate-pulse" wire:target="testConnection,saveConnection" /> Test connection
        </button>
        <span class="grow"></span>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveConnection"><x-icon name="check" /> Save connection</button>
    </div>
</form>
