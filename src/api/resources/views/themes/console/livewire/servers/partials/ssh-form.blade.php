{{-- The Console theme's ssh.conf: a server entered by hand (host, user, port, key, jump
     host), saved into deploy.me's own ssh config, and a test of the connection. --}}
<form wire:submit="saveConnection" class="con-box con-wide con-conf max-w-3xl" aria-label="SSH connection">
    <span class="con-box-t"><b>ssh.conf</b> · Host {{ $server->ssh_alias }} · {{ 'entered by hand' }}@if ($connectionNotice)<span class="t-ok">· ✓ {{ $connectionNotice }}</span>@endif</span>
    <div class="grid grid-cols-[12ch_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
        <label for="manual-alias" class="t-v">alias</label>
        <div>
            <input id="manual-alias" type="text" wire:model="manualAlias" readonly class="input input-sm w-full max-w-md t-mute" spellcheck="false">
            @error('manualAlias')<span class="t-err block">{{ $message }}</span>@enderror
        </div>
        <label for="manual-user" class="t-v">user</label>
        <div>
            <input id="manual-user" type="text" wire:model="manualUser" class="input input-sm w-full max-w-md" placeholder="deploy" spellcheck="false" autocomplete="off" autocapitalize="off">
            @error('manualUser')<span class="t-err block">{{ $message }}</span>@enderror
        </div>
        <label for="manual-host" class="t-v">hostname</label>
        <div>
            <input id="manual-host" type="text" wire:model="manualHost" class="input input-sm w-full max-w-md" placeholder="203.0.113.10" spellcheck="false" autocomplete="off" autocapitalize="off">
            @error('manualHost')<span class="t-err block">{{ $message }}</span>@enderror
        </div>
        <label for="manual-port" class="t-v">port</label>
        <div>
            <input id="manual-port" type="text" inputmode="numeric" wire:model="manualPort" class="input input-sm w-[10ch]" placeholder="22">
            @error('manualPort')<span class="t-err block">{{ $message }}</span>@enderror
        </div>
        <label for="manual-key" class="t-v">identityfile</label>
        <div>
            <input id="manual-key" type="text" wire:model="manualKey" class="input input-sm w-full max-w-md" placeholder="~/.ssh/id_ed25519" spellcheck="false" autocomplete="off" autocapitalize="off">
            <span class="t-faint block"># like ssh -i, inside ~/.ssh · empty: the keys your ssh-agent holds</span>
            @error('manualKey')<span class="t-err block break-words">{{ $message }}</span>@enderror
        </div>
        <label for="manual-jump" class="t-v">proxyjump</label>
        <div>
            <input id="manual-jump" type="text" wire:model="manualJump" list="manual-jumps-con" class="input input-sm w-full max-w-md" placeholder="optional" spellcheck="false" autocomplete="off">
            <datalist id="manual-jumps-con">@foreach ($jumpChoices as $choice)<option value="{{ $choice }}"></option>@endforeach</datalist>
            @error('manualJump')<span class="t-err block">{{ $message }}</span>@enderror
        </div>
    </div>

    @if ($connectionTest)
        <div class="mt-3 leading-[1.75]" role="status">
            <div><span class="t-mute">$</span> ssh {{ $server->ssh_alias }} true</div>
            <div class="break-words"><span class="{{ $connectionTest['ok'] ? 't-ok' : 't-err' }}">{{ $connectionTest['ok'] ? '✓' : '✕' }}</span> {{ $connectionTest['detail'] }}</div>
            @if ($connectionTest['ssh'])<div class="t-faint break-all">{{ $connectionTest['ssh'] }}</div>@endif
        </div>
    @endif

    @if ($connectionHostKey)
        <div class="mt-3 flex flex-col gap-1.5">
            <span class="t-warn"># compare with ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the server</span>
            @foreach ($connectionHostKey['keys'] as $key)
                <span class="break-all">{{ $key['type'] }} {{ $key['fingerprint'] }}</span>
            @endforeach
            <span><button type="button" class="con-act border-primary text-primary" wire:click="trustConnectionKey" wire:loading.attr="disabled" wire:target="trustConnectionKey">trust and test again</button></span>
        </div>
    @endif

    <div class="flex items-center gap-2 mt-4 pt-3 border-t border-line">
        <button type="button" class="con-act" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection,saveConnection,trustConnectionKey">test connection</button>
        <span class="grow"></span>
        <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="saveConnection">Save</button>
    </div>
</form>
