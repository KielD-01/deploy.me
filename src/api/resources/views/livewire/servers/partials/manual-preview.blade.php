{{-- What deploy.me writes for the server being entered by hand, as it's typed, and the
     ssh command that means the same. --}}
<section class="card bg-base-100 border border-line"
         x-data="{
             get block() {
                 const w = this.$wire, lines = ['Host ' + (w.manualAlias || 'alias'), '  HostName ' + (w.manualHost || '…'), '  User ' + (w.manualUser || '…'), '  Port ' + (w.manualPort || '22')];
                 if (w.manualKey) lines.push('  IdentityFile ' + w.manualKey, '  IdentitiesOnly yes');
                 if (w.manualJump) lines.push('  ProxyJump ' + w.manualJump);
                 return lines.join('\n');
             },
             get command() {
                 const w = this.$wire;
                 return ['ssh', w.manualKey && '-i ' + w.manualKey, w.manualPort && w.manualPort !== '22' && '-p ' + w.manualPort, w.manualJump && '-J ' + w.manualJump, (w.manualUser || 'user') + '@' + (w.manualHost || 'host')].filter(Boolean).join(' ');
             },
         }">
    <header class="flex flex-wrap items-center gap-x-2.5 min-h-[52px] px-4 py-2 border-b border-line">
        <h2 class="text-[15px] font-semibold">Host block</h2>
        <span class="text-[13px] text-muted">in deploy.me’s own ssh config, not in yours</span>
    </header>
    <div class="p-4 flex flex-col gap-3">
        <pre class="bg-sunk rounded-box p-3 font-mono text-[12px] leading-5 whitespace-pre-wrap break-words" x-text="block">Host {{ $manualAlias ?: 'alias' }}</pre>
        <p class="text-xs text-muted break-words">The same as <span class="mono" x-text="command">ssh user@host</span></p>
    </div>
</section>
