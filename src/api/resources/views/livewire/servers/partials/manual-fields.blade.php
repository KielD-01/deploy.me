{{-- The fields of a server entered by hand, in the order `ssh -i key -p port user@host`
     reads them, inside the caller's six-column grid: Add server → Enter manually (with a
     Name beside the alias), and the SSH connection on such a server's Settings tab,
     where the alias stays ($aliasLocked). --}}
<label @class(['flex flex-col gap-1.5 min-w-0', 'sm:col-span-3' => ! $aliasLocked, 'sm:col-span-6' => $aliasLocked])>
    <span class="text-[13px] font-medium">Alias</span>
    <input type="text" wire:model.live.blur="manualAlias" @readonly($aliasLocked) @class(['input w-full font-mono', 'text-muted' => $aliasLocked]) placeholder="kz-prod" spellcheck="false" autocomplete="off" autocapitalize="off">
    <span class="text-xs text-muted">{{ $aliasLocked ? 'What ssh and deploy.me call this server. It stays while the server is added.' : 'What ssh and deploy.me will call it. It follows the name until you change it.' }}</span>
    @error('manualAlias')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
<label class="flex flex-col gap-1.5 sm:col-span-2 min-w-0">
    <span class="text-[13px] font-medium">User</span>
    <input type="text" wire:model.live.blur="manualUser" class="input w-full font-mono" placeholder="deploy" spellcheck="false" autocomplete="off" autocapitalize="off">
    @error('manualUser')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
<label class="flex flex-col gap-1.5 sm:col-span-3 min-w-0">
    <span class="text-[13px] font-medium">Host</span>
    <input type="text" wire:model.live.blur="manualHost" class="input w-full font-mono" placeholder="203.0.113.10" spellcheck="false" autocomplete="off" autocapitalize="off">
    @error('manualHost')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
<label class="flex flex-col gap-1.5 sm:col-span-1 min-w-0">
    <span class="text-[13px] font-medium">Port</span>
    <input type="text" inputmode="numeric" wire:model.live.blur="manualPort" class="input w-full font-mono" placeholder="22">
    @error('manualPort')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
<p class="text-xs text-muted sm:col-span-6 -mt-2">A host name or an IP address. Pasted <span class="mono">user@host:port</span> is split into the three fields.</p>
<label class="flex flex-col gap-1.5 sm:col-span-6 min-w-0">
    <span class="text-[13px] font-medium">Key file <span class="text-muted font-normal">optional</span></span>
    <input type="text" wire:model.live.blur="manualKey" class="input w-full font-mono" placeholder="~/.ssh/id_ed25519" spellcheck="false" autocomplete="off" autocapitalize="off">
    <span class="text-xs text-muted">The private key, as <span class="mono">ssh -i</span> takes it, inside <span class="mono">~/.ssh</span>. Left empty, ssh offers the keys your ssh-agent holds.</span>
    @error('manualKey')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
<label class="flex flex-col gap-1.5 sm:col-span-6 min-w-0">
    <span class="text-[13px] font-medium">Jump host <span class="text-muted font-normal">optional</span></span>
    <input type="text" wire:model.live.blur="manualJump" list="manual-jumps" class="input w-full font-mono" placeholder="bastion" spellcheck="false" autocomplete="off" autocapitalize="off">
    <datalist id="manual-jumps">@foreach ($choices as $choice)<option value="{{ $choice }}"></option>@endforeach</datalist>
    <span class="text-xs text-muted">A host from your ~/.ssh/config, or another server entered here, that ssh goes through first (ProxyJump).</span>
    @error('manualJump')<span class="text-error text-xs break-words">{{ $message }}</span>@enderror
</label>
