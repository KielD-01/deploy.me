# 📸 Gallery

deploy.me has two themes, switched in **Settings → Appearance**. The **Console** theme (the default) shows the
app as a tmux session. **Classic** has a top bar, cards and tables. Both do the same work on
the same data. Click a picture to see it full size.

> [!NOTE]
> These are screenshots of a demo: three example servers (documentation IP ranges), three
> projects with git histories of their own, and a week of runs. It was all made by deploy.me's own
> code, with a stand-in for ssh: `php artisan db:seed --class=DemoSeeder` on an empty database.

## Console

<table>
<tr>
<td width="50%" valign="top">
<a href="assets/gallery/console-session.webp"><img src="assets/gallery/console-session.webp" alt="The Console session: a shell with every target's status, the latest run's log, htop and git panes"></a>
<b>The session.</b> A shell (here <code>status</code>, every target at a glance), the latest run's
<code>tail -f</code>, <code>htop</code> for a server and <code>git log</code> for a project. Split, zoom and move
between panes with tmux's keys.
</td>
<td width="50%" valign="top">
<a href="assets/gallery/console-deploy-plan.webp"><img src="assets/gallery/console-deploy-plan.webp" alt="Deploying from the shell: the commit picked, the plan, and a question before anything starts"></a>
<b>Deploy from the shell.</b> <code>deploy atelier edge-fra</code> asks for the commit and shows the
plan: what changes, the strategy and why, migrations, safety. Then it asks before it starts.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/console-run.webp"><img src="assets/gallery/console-run.webp" alt="A run as tail -f: every step with its result and duration"></a>
<b>A run, step by step.</b> GHCR images pinned to the commit, migrations, the new colour started
beside the old one, the health gate, pruning. Each step folds open to its log.
</td>
<td valign="top">
<a href="assets/gallery/console-rolled-back.webp"><img src="assets/gallery/console-rolled-back.webp" alt="A run whose health check failed, switched back automatically"></a>
<b>When the health check says no.</b> The new release went live, its API never turned healthy,
and deploy.me switched back to the previous release by itself.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/console-server.webp"><img src="assets/gallery/console-server.webp" alt="A server as htop: meters, the last day's CPU and memory, the busiest processes with their containers"></a>
<b>A server.</b> Meters, the last day's CPU and memory, the busiest processes by CPU, memory and
TCP traffic (each with its container), Compose stacks, health checks and the ssh setup.
</td>
<td valign="top">
<a href="assets/gallery/console-project.webp"><img src="assets/gallery/console-project.webp" alt="A project as git log --graph: where each commit is live, rollback points and the deploy settings"></a>
<b>A project.</b> The branch as <code>git log --graph</code>: where each commit is live, which
releases a rollback can go back to, migrations, and a deploy button on every commit.
</td>
</tr>
</table>

## Classic

<table>
<tr>
<td width="50%" valign="top">
<a href="assets/gallery/classic-dashboard.webp"><img src="assets/gallery/classic-dashboard.webp" alt="The dashboard: servers, recent deployments and health"></a>
<b>Dashboard.</b> Servers and their load, today's runs, health, and what needs attention.
</td>
<td width="50%" valign="top">
<a href="assets/gallery/classic-servers.webp"><img src="assets/gallery/classic-servers.webp" alt="The servers list, from ~/.ssh/config"></a>
<b>Servers.</b> Straight from <code>~/.ssh/config</code>, probed on a schedule.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/classic-server.webp"><img src="assets/gallery/classic-server.webp" alt="A server's overview: resources, the last day, the busiest processes"></a>
<b>A server's overview.</b> Resources, the last 24 hours, and the busiest processes from any probe
of the last day.
</td>
<td valign="top">
<a href="assets/gallery/classic-projects.webp"><img src="assets/gallery/classic-projects.webp" alt="The projects list with deploy mode, targets, last deploy and CI"></a>
<b>Projects.</b> From GitHub or a local folder, with how each one deploys, where, and its CI.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/classic-project.webp"><img src="assets/gallery/classic-project.webp" alt="A project's targets"></a>
<b>A project's targets.</b> Every server it deploys to, its live release and its health.
</td>
<td valign="top">
<a href="assets/gallery/classic-deploy-dialog.webp"><img src="assets/gallery/classic-deploy-dialog.webp" alt="The deploy dialog: pick a commit, see which strategy delivers it"></a>
<b>The deploy dialog.</b> Pick a commit, and see which delivery strategy will ship it and why the
others won't.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/classic-run.webp"><img src="assets/gallery/classic-run.webp" alt="A run with its steps and a live, redacted log"></a>
<b>A run.</b> Steps on the left, the live log on the right, with secrets masked.
</td>
<td valign="top">
<a href="assets/gallery/classic-runs.webp"><img src="assets/gallery/classic-runs.webp" alt="Every run, newest first"></a>
<b>Every run.</b> Deploys, rollbacks and prepares, by hand or started when CI went green.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/classic-env.webp"><img src="assets/gallery/classic-env.webp" alt="Env files, encrypted and versioned, secrets masked"></a>
<b>Env files.</b> Encrypted and versioned. Secrets stay masked until you confirm with a passkey.
</td>
<td valign="top">
<a href="assets/gallery/classic-folders.webp"><img src="assets/gallery/classic-folders.webp" alt="A folder mapping between the Mac and a server"></a>
<b>Folder sync.</b> A folder on the Mac and one on a server, one way, on a schedule, with previews.
</td>
</tr>
<tr>
<td valign="top">
<a href="assets/gallery/classic-audit.webp"><img src="assets/gallery/classic-audit.webp" alt="The audit log, what was done by hand"></a>
<b>Audit log.</b> Who did what, and how it ended, but never a value. Nothing in it can be edited.
</td>
<td valign="top">
<a href="assets/gallery/classic-appearance.webp"><img src="assets/gallery/classic-appearance.webp" alt="Choosing a theme, mode and density"></a>
<b>Appearance.</b> Pick a theme, dark or light, and the density. The choice follows you to
every browser.
</td>
</tr>
</table>
