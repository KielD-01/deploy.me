<div class="flex flex-col gap-5">
    <x-page-header title="Component kit" subtitle="daisyUI 5 in the deploy.me theme. Compare it with design/canvas." />

    <div class="grid grid-cols-2 gap-4">
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Buttons</h2></header>
            <div class="p-4 flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-primary"><x-icon name="deploy" /> Deploy</button>
                <button type="button" class="btn">Probe now</button>
                <button type="button" class="btn btn-ghost">Cancel</button>
                <button type="button" class="btn btn-outline btn-error"><x-icon name="stop" /> Cancel deploy</button>
                <button type="button" class="btn btn-sm"><x-icon name="refresh" :size="14" /> Small</button>
                <button type="button" class="btn btn-xs"><x-icon name="rollback" :size="12" /> Roll back</button>
                <button type="button" class="btn btn-sm btn-square" aria-label="More"><x-icon name="more" /></button>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Badges and status</h2></header>
            <div class="p-4 flex flex-wrap items-center gap-2">
                <span class="badge badge-soft badge-success gap-1"><x-icon name="check" :size="12" /> Healthy</span>
                <span class="badge badge-soft badge-info gap-1"><x-icon name="loader" :size="12" /> Deploying</span>
                <span class="badge badge-soft badge-warning gap-1"><x-icon name="rollback" :size="12" /> Rolled back</span>
                <span class="badge badge-soft badge-error gap-1"><x-icon name="x" :size="12" /> Failed</span>
                <span class="badge badge-soft badge-secondary">Production</span>
                <span class="badge badge-soft badge-accent">Staging</span>
                <span class="badge badge-ghost">Development</span>
                <span class="badge badge-outline">Releases · keep 5</span>
                <span class="inline-flex items-center gap-1.5 text-[13px]"><span class="status status-success"></span> Online</span>
                <span class="inline-flex items-center gap-1.5 text-[13px]"><span class="status status-warning"></span> Disk almost full</span>
                <span class="inline-flex items-center gap-1.5 text-[13px]"><span class="status status-error"></span> Unreachable</span>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Form controls</h2></header>
            <div class="p-4 grid grid-cols-2 gap-4">
                <label class="flex flex-col gap-1.5"><span class="text-[13px] font-medium">Stack name</span><input type="text" value="mr-studio" class="input w-full font-mono"></label>
                <label class="flex flex-col gap-1.5"><span class="text-[13px] font-medium">Probe every</span><select class="select w-full"><option>5 minutes</option><option>1 minute</option></select></label>
                <label class="flex items-center gap-2.5 text-[13px]"><input type="checkbox" class="toggle toggle-primary toggle-sm" checked> Versionable</label>
                <label class="flex items-center gap-2.5 text-[13px]"><input type="checkbox" class="checkbox checkbox-sm checkbox-primary" checked> Back up the database first</label>
                <div class="col-span-2 flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Releases to keep</span>
                    <input type="range" min="1" max="10" value="5" step="1" class="range range-primary range-xs w-full">
                    <div class="flex justify-between px-1 text-xs text-muted">@foreach (range(1, 10) as $n)<span>{{ $n }}</span>@endforeach</div>
                </div>
                <div class="col-span-2 join">
                    <button type="button" class="btn btn-sm join-item btn-active">Production</button>
                    <button type="button" class="btn btn-sm join-item">Staging</button>
                    <button type="button" class="btn btn-sm join-item">Development</button>
                </div>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Navigation</h2></header>
            <div class="p-4 flex flex-col gap-4">
                <div role="tablist" class="tabs tabs-border">
                    <a role="tab" class="tab tab-active">Targets</a>
                    <a role="tab" class="tab">Releases</a>
                    <a role="tab" class="tab">Environment</a>
                    <a role="tab" class="tab">Hooks</a>
                    <a role="tab" class="tab">Settings</a>
                </div>
                <ul class="steps text-[13px]">
                    <li class="step step-primary">Pick host</li>
                    <li class="step step-primary">Connection</li>
                    <li class="step">Deploy path</li>
                    <li class="step">Review</li>
                </ul>
                <div class="breadcrumbs text-[13px] font-mono"><ul><li>/</li><li>opt</li><li>srv</li></ul></div>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Alerts and meters</h2></header>
            <div class="p-4 flex flex-col gap-3">
                <div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> GitHub Actions deploys this project too.</div>
                <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> Health check failed after 180 s.</div>
                <div role="alert" class="alert alert-soft alert-info text-[13px]"><x-icon name="info" /> Keys are read from ~/.ssh, mounted read-only.</div>
                <div class="flex items-center gap-3 text-xs"><span class="w-14 text-muted">CPU</span><progress class="progress progress-primary grow" value="12" max="100"></progress><span class="w-8 text-right tabular-nums">12%</span></div>
                <div class="flex items-center gap-3 text-xs"><span class="w-14 text-muted">Memory</span><progress class="progress progress-warning grow" value="78" max="100"></progress><span class="w-8 text-right tabular-nums">78%</span></div>
                <div class="flex items-center gap-3 text-xs"><span class="w-14 text-muted">Disk</span><progress class="progress progress-error grow" value="92" max="100"></progress><span class="w-8 text-right tabular-nums">92%</span></div>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Log pane</h2></header>
            <div class="p-3">
                <pre class="bg-log text-log-content font-mono text-xs leading-[19px] rounded-lg p-4 overflow-x-auto"><span class="text-subtle">10:58:41</span> <span class="text-primary">▸ Pull images</span>
<span class="text-subtle">10:58:42</span> api Pulling
<span class="text-subtle">10:58:55</span> <span class="text-success">web Pulled</span>
<span class="text-subtle">10:59:01</span> <span class="text-warning">api unhealthy (3/10)</span></pre>
            </div>
        </section>
    </div>

    <section class="card bg-base-100 border border-line">
        <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Table</h2></header>
        <table class="table">
            <thead><tr><th>Server</th><th>Directory</th><th>Current release</th><th>Health</th></tr></thead>
            <tbody>
                <tr><td class="font-semibold">hetzner-prod</td><td class="mono">/opt/srv/acme-erp</td><td class="mono">20260909-101500 · 9f8e7d6</td><td><span class="badge badge-soft badge-success">Healthy</span></td></tr>
                <tr><td class="font-semibold">do-staging</td><td class="mono">/srv/staging/acme-erp</td><td class="mono">20260908-172233 · 9f8e7d6</td><td><span class="badge badge-soft badge-success">Healthy</span></td></tr>
            </tbody>
        </table>
    </section>
</div>
