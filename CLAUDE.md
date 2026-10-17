# deploy.me

A local, Dockerized Laravel 13 admin app that deploys the user's projects to their
servers over SSH: servers come from `~/.ssh/config`, projects from GitHub or local
folders, with release folders (keep N), Docker Compose stacks, live logs over Reverb,
and health checks on queue workers run by supervisor.

- Plan: `~/.claude/plans/this-is-a-project-goofy-bengio.md` (milestones M0–M8).
- Design: `design/canvas/*.dc.html` + `canvas.json` (published design canvas). Build
  new pages to match it.

## Layout

- `core/local/php/` — PHP image (FrankenPHP 8.5), entrypoint, git askpass helper,
  supervisor programs (queue workers + scheduler).
- `src/api/` — the Laravel app. Livewire 4 class components in `app/Livewire`, layout
  `resources/views/layouts/app.blade.php`, daisyUI 5 theme in `resources/css/app.css`
  (tokens from a sibling project's design system), icons via `<x-icon name="…" />`.
- `docs/` — the user guide (`usage.md`) and the MCP guide (`mcp.md`).
- `bin/install` (and `bin/install.ps1` for Windows → WSL2) runs the guided install;
  `bin/configure` writes `.env` (COMPOSE_FILE, stack mode, DEPLOYME_UID/GID, TZ, project
  roots) and `compose.host.yml` (root and ssh-agent mounts); `bin/trust-ca` trusts the edge's
  CA per OS; `bin/lib.sh` is what they share. Stack modes: `shared` (the external
  proxy-network stack, this machine) or `standalone` (`compose.standalone.yml`:
  deployme-postgres/-valkey/-caddy with the aliases postgres/redis). On Linux/WSL the
  containers run as the host UID (the image's `deployme` user, home /root); on macOS as root.
  `docs/install.md` is the per-OS guide.
- `bin/` — `db-create.sh` (the role + DBs, either stack), `doctor.sh` (host-side checks),
  `backup.sh` (the database and APP_KEY into `~/Backups/deployme`, nightly through a
  LaunchAgent on macOS, a systemd user timer or cron on Linux/WSL: everything else can be
  rebuilt from git and the servers).
- `docker-compose.local.yml` (+ `compose.standalone.yml`, generated `compose.host.yml`),
  `Makefile` (`make help`).

## Running

```bash
bin/install    # guided first run on macOS/Linux/WSL2 (configure, init, trust-ca, enroll)
make configure # re-describe this machine (.env, compose.host.yml)
make init      # first run: database, image, containers, assets, setup, doctor
make enroll    # one-time link to set up a passkey or password (first run, or a lost way in)
make up        # start · make down · make logs s=deployme-worker
make test      # Pest, in the container, against deployme_test
make sandbox   # local SSH server (alias "sandbox") · make test-ssh for real-ssh tests
make doctor    # infra, TLS, websockets, then `php artisan deployme:doctor`
make assets    # build frontend (make dev for Vite + HMR at vite.deployme.localhost)
```

The web container applies pending migrations when it starts, so a change that brings one
doesn't wait for `make migrate`.

## How it fits together

- **Sign-in is a passkey or a password** (Fortify passkeys → `laravel/passkeys`, browser
  side `@laravel/passkeys` in `resources/js/passkeys.js`). One account; no registration or
  email resets. `deployme:enroll` prints a one-time link (`App\Auth\EnrollmentLinks`) that
  signs in and counts as a confirmation; Settings → Account then registers a passkey or sets
  a password. Password sign-in accepts only a password its owner chose
  (`users.password_set_at`; until then the column holds a random value), five tries a minute,
  and asks for an authenticator code when Fortify 2FA is confirmed on (optional, recovery
  codes). `DeletePasskeyKeepingAWayIn` and `removePassword` never remove the last way in.
  Sensitive routes use `password.confirm`, which a passkey or the password satisfies.
- **SSH** (`app/Platform/Ssh`): every call runs the system `ssh -F /run/deployme/ssh/config`
  (`SshConfigCompiler`: enforced options, then the user's `~/.ssh/config`, then extras
  like the sandbox). Host keys are trusted on first use and stored in `ssh_host_keys`
  (`KnownHosts`). Remote work is a script from `resources/ssh-scripts/` sent over stdin
  (`RemoteScript`) — never user data in argv. Tests use `Testing\FakeSshClient`.
- **Servers** (`app/Platform/Remote`, `app/Livewire/Servers`): `ServerProber` runs
  `probe.sh` (JSON between sentinels), `RecordProbe` stores snapshots and status,
  `ProbeServer` runs on `redis-probes`, the scheduler dispatches due probes each minute,
  and `ServerProbed` updates open pages over Reverb. A scheduled probe retries once on a
  dropped connection; after a failure the server is probed again within a minute.
  Scheduled probes start at second `deployme.probe.second` (30) of the minute, clear of the
  cron jobs and Laravel schedulers that start on it (a burst of PHP boots on every fifth
  minute read as 100% CPU), and `next_probe_at` is rounded down to the minute so that wait
  doesn't stretch the interval.
  - Each probe also records the busiest processes over a one-second sample, ten each by
    CPU (100% is one core), resident memory and TCP traffic, in `server_snapshots.processes`
    (`ProbeResult::processes`). They're kept as long as the snapshots and shown on the
    server's Overview, with a picker for the last day's probes. CPU and memory come from
    `/proc/<pid>/stat` as the ssh user, leaving out the probe's own processes; only names
    (comm) are read, never command lines. The kernel doesn't count traffic per process, so
    `probe-net.sh` (sent as `NET_SAMPLER`) runs as root under `sudo -n`: `ss -tie` inside
    every network namespace at both ends of its own window, after the CPU second so its
    work (about a core for seconds on a host with dozens of containers) isn't counted as
    the machine's, whose figure covers only the sleep. Each socket is matched to its process
    through `/proc/<pid>/fd`. UDP and connections that open and close inside the window
    aren't counted, and without passwordless sudo (or `ss`/`nsenter`) the network list is
    left out, with `net_note` saying why.
- **Projects** (`app/Platform/{Detection,Git,GitHub,Local}`, `app/Livewire/Projects`):
  an import reads a local folder (`LocalInspector`: git state through `GitRunner`, which
  runs git with a clean command-line config and passes tokens only through
  `GIT_ASKPASS`) or a GitHub repository through the API (`GitHubInspector`; nothing is
  cloned). `ProjectDetector` finds workflows (GHCR image templates, jobs that deploy over
  SSH), Compose files (stack name, app services, image pins, each service's command and
  stop grace period), the framework (up to three folders deep) and env templates. A
  service behind a profile (a migrate one-off) is never an app service. The result is stored in `projects.detection`; the
  `Detection` class wraps it and suggests the deploy settings.
  - GitHub tokens are stored encrypted in `credentials`.
  - Pushes to GitHub run in the worker (`PublishRepository`), since only the worker
    mounts the project folders read-write.
  - The scheduler polls CI state (`PollCiStatus`, using ETags) and `ProjectCiUpdated`
    broadcasts changes.
  - Env files are encrypted and versioned (`ProjectEnvFile::revise`). Revealing,
    editing or importing them needs a passkey (`Livewire\Concerns\ConfirmsIdentity`).
  - A project deployed before deploy.me existed keeps its env files only on the server:
    the Environment tab imports them (`Actions\Projects\ImportEnvFromServer`,
    `deploy-read-env.sh` — the compose file's `env_file` paths plus `.env`, read from
    `shared/` first, then `current/`, then the folder itself). They arrive as versions
    like any other save, and only their paths reach the log. Until deploy.me has them it
    can't tell which database to back up before it migrates.
  - Local imports stay inside `DEPLOYME_PROJECT_ROOTS` (`ProjectRoots`).
  - Tests use `tempGitRepo()` and `fakeGitHubRepository()` from `tests/Helpers.php`.
- **Deploys** (`app/Deploy`, `app/Livewire/Deployments`, `app/Livewire/Projects/Targets`):
  a target is a project on a server at `{base folder}/{slug}`.
  - `StartDeployment` queues `RunDeployment` on `redis-deploys`. A partial unique index
    allows one active run per target.
  - The deploy dialog asks once. Each opening gets an `attempt`, and `DeployDialog::deploy()`
    (under a cache lock) starts nothing on a target that attempt already started a run on,
    so a double click or a resent request lands on the same run. The buttons that open the
    dialog and its own Deploy button spin and ignore clicks until the dialog shows
    (`deploy-dialog-opened`) or `$wire.deploy()` answers that a run is on its way. The
    dialog's middle scrolls, so its header and buttons stay on screen.
  - `PipelineRunner` runs the steps `PipelineFactory` picks for the kind and records each
    one. Steps call the `deploy-*.sh` scripts in `resources/ssh-scripts`, which print
    `::deployme::key=value` markers for the step to read.
  - A remote `mkdir` lock guards each folder.
  - `Preflight` stops a deploy, redeploy or rollback while another Compose
    project already runs from the folder: the stack name doesn't match what
    started it, and bringing the project's stack up beside it would give it
    volumes of its own. Other kinds only say so in the log.
  - Every deploy script runs in a session of its own (`setsid --wait`) and notes its
    process group in `.deploy/runs/<run>.pgid`. When a cancel or a timeout makes deploy.me
    let go of it, `deploy-kill.sh` stops what it left running on the server.
  - `StepLogger` batches log lines, redacts secrets and broadcasts them. The run page's
    log is Alpine (`resources/js/deploy-log.js`).
  - Kinds: `bootstrap` (Prepare: folders and env files; it keeps files an earlier
    deployment left in the folder), `deploy`/`redeploy`, and `rollback`.
  - A deploy first resolves a delivery strategy (`app/Deploy/Delivery`): the project's
    ordered list, where the first strategy that applies wins. The trace is stored on
    the run and shown in the deploy dialog. A `Verdict` whose state is `Error` means the
    check itself failed (GitHub or ghcr.io didn't answer), not that the strategy doesn't
    apply: it's passed over the same way, isn't cached, and shows in its own colour.
    A pending strategy doesn't disable the deploy dialog's button — it says it will wait.
  - The deploy then exports the commit, or takes the folder as it is, and uploads it
    with rsync (`Upload\Uploader`). Versionable projects get `releases/<Ymd-His>/`,
    hard-linked against the live release. In-place projects are staged in
    `.deploy/incoming/<release>` (hard-linked against the live files), then
    `deploy-sync-in-place.sh` syncs them into the project folder: what the release no
    longer has goes, while `.deploy/`, `.git`, env files, shared folders and what the
    `.gitignore` files name stay (unless the release brings its own copy).
  - Shared folders and env files are symlinks into `shared/`. A shared path named like a
    file (a dot in its name) that neither `shared/` nor the release has isn't linked,
    rather than becoming an empty folder (`deploy-link-shared.sh`).
  - `current` switches atomically (`deploy-activate.sh`).
  - When something fails after the switch and auto-rollback is on, the `RollBack`
    cleanup step switches back.
  - A rollback of a project that deploys **in place** has no folder to point
    `current` at, so it puts the release's own `image_pins` back into
    `.deployme-pins.env` (`RestorePins`) and brings the stack up on them again —
    which is the whole rollback for a project whose code is in the image. Only a
    release that recorded pins can be rolled back to (`Release::canRollBackTo`),
    and the same mechanism is what the `RollBack` cleanup step uses in place.
    `PickRelease` puts the release's strategy on the run, so a rollback of a
    GHCR project logs in and pulls the pinned images again.
  - `PruneReleases` keeps `keep_releases` releases, always including the one a
    rollback would need. `PruneImages` then removes the images those releases
    pinned (`.deployme-pins.env`, so only what deploy.me pinned itself), keeping
    the live release's, the rollback target's, and whatever a container still
    uses (`deploy-prune-images.sh`).
  - Docker projects (`Compose::builtin`) run these steps, all from the release's own
    folder, with the project's stack name:
    - `CheckStack`: `compose config --quiet` from the new release, with its env
      files and pins, so a compose file the release broke stops the deploy before
      anything is built, migrated or replaced. Pre-flight has already checked that
      the external networks the compose file names (`external_networks`) are on
      the server, since Compose never creates one.
    - build the images, or pull them (the GHCR strategy, pinned by `WritePins`)
    - optionally `pg_dump` before migrating (`deploy-db-backup.sh`). The role it
      connects as is `projects.backup_role`: an UPPER_CASE env key read from the
      project's env file (`DB_USERNAME` by default, with the `DB_PASSWORD` beside
      it), or a role name written out, which connects over the container's own
      socket without a password (`postgres`, for its superuser). pg_dump reads
      with row security off, so a role RLS applies to is refused: the script
      recognises that in pg_dump's stderr and the step says what to change.
      `projects.backup_keep` dumps stay in `.deploy/backups`.
    - migrate in a one-off container of the migrate service (every `run` one-off gets a
      temporary compose file that resets the service's labels, so the edge doesn't
      route live requests to it). A migrate service whose own command migrates (like
      warehouse's `warehouse-migrate`, with `--database=pgsql_direct`) runs that command;
      any other gets `php artisan migrate --force`.
    - `up -d`, then `up -d --force-recreate --no-deps` of the app services `up` didn't
      replace (a redeploy of the same images), so each restarts once. The step's
      timeout covers the longest `stop_grace_period` (a worker finishing its job).
      Then it retires the containers of services the release no longer has (renamed
      or dropped: `COMPOSE_PROFILES='*' config --services` says what it has), which
      would otherwise go on running and taking traffic by their labels. One-offs are
      never touched, and nothing is when Compose can't list the services.
    - with **blue/green** on (`projects.blue_green`), the services the edge routes to
      that have a health check of their own start *beside* the running ones first
      (`RollServices` → `deploy-compose-roll.sh`: `up --scale` to twice the count, wait
      for the new containers, then stop and remove the old ones). The colour alternates
      per deploy and is kept on the release; a colour that never turns healthy is
      removed again and the old one keeps serving, so a deploy that fails there costs no
      downtime. The recreate step then only handles what wasn't rolled. A service
      with nothing running yet (just renamed) starts fresh, one the release doesn't
      have is left to the recreate step (`::deployme::rolled`), and the new
      containers are renamed after their colour — `<service>-<colour>`, with `-1`,
      `-2` when there are several — since Compose can't name a service it scales and
      its own numbers grow with every roll. Compose and the edge go by labels, so
      only the name changes; a rename that fails never fails the deploy.
    - wait for their health checks
  - The health gate (`HealthCheck`) is three checks in order: a Docker project's
    app services up and healthy, the project's health URL asked from the server,
    and `projects.health_command` run on the server in the release's folder
    (`deploy-hook.sh`). Any of them failing fails the gate, so auto-rollback
    switches back. The command runs once per deploy, not on the scheduled checks.

    A project's own deploy script (`activation=script`) replaces them. It runs from the
    release with the release's image pins in its environment (`IMAGE_TAG=…`) and, for the
    GHCR strategy, the deploy's ghcr.io login as `DOCKER_CONFIG`. A rollback logs in and
    runs the old release's script again.
  - `adopt` records what's already in an in-place folder as the live release: the
    commit (git, or the `CURRENT_SHA` in a `.deploy-state` the folder's own deploy
    script wrote), the image tags its `.env` pins — kept as the release's
    `image_pins`, so a rollback has something to put back — and the running stack.
    It starts from the project's Targets menu, or from the Adopt button on a
    server's Stacks tab, which assigns the project first when it isn't there yet.
  - Strategies:
    - `ghcr_image`: CI's images for the commit, checked through the GitHub API and
      ghcr.io (`ImagePlan`; an image the compose file names `${REGISTRY}/api` is looked
      for where the workflow pushes it). The server logs in for that one pull only.
      Which images and tags a commit publishes is `projects.image_templates`: rows of
      `{var, kind, image, tag}` where image and tag may use `{owner} {repo} {sha} {sha7}`,
      edited on the project's Settings tab. Null follows `ImagePlan::detected()`, which is
      also what "Back to detection" restores.
    - `push_archive`
    - `push_rsync`
    - `server_git`: the server fetches into `.deploy/repo.git` itself.
  - While a strategy waits for CI, the run pauses as `waiting_ci`, holding no lock. It
    is re-checked every 20 s for up to an hour; "Deploy now" forces the next strategy.
  - `PollCi` deploys on its own when CI passes on the branch (`projects.auto_deploy`).
    Only the branch's head counts (`GitHubClient::branchHead`): GitHub's runs list has
    come back with months-old runs first, or empty, and that once deployed an August
    commit over September's. A target whose live release is already at or past the head
    (GitHub's compare says `behind` or `identical`) is left alone: going back is a
    rollback, and only a person starts one.
  - The sandbox's docker is a shim (`core/local/sshd-target/docker`). It logs every call
    to `/tmp/docker-calls.log` (plus the lines of a compose file given by absolute path,
    like a one-off's override, prefixed `  | `) and takes cues from `.sandbox-unhealthy` and
    `.sandbox-migrations` files in a release.
  - Tests use `demoTarget()` and `fakeDeployScripts()`. `make test-ssh` runs the scripts
    for real against the sandbox. `tests/Feature/Deploy/PipelineMatrixTest.php` is the
    guard against a step landing in the wrong pipeline: the exact step keys per kind, and
    the steps that actually run per deploy mode × delivery strategy.
- **Converting to releases** (`app/Deploy/Conversion`, `app/Livewire/Projects/Convert`): a
  versionable project whose folder still holds an earlier in-place deployment is converted
  before its first deploy. Preflight refuses deploys until then, and the Targets tab says so.
  - `ReadTargetFolder::start()` queues `ReadFolder`, which runs `deploy-convert-scan.sh`
    (read-only: items, sizes, a git checkout, the stack's mounts, and the env files and
    binds its compose files name). `RepositoryComparison` then finds the commit the files
    came from, by checksum, with an rsync dry run *from* the server *into* an export in the
    worker, so it can't change the server. The target keeps the result in `folder_scan`.
    A read job for a target nobody marked as reading does nothing.
  - `ConversionPlanner` turns the read into rows (release / shared / stays, and why) and
    checks: one filesystem, Compose, a CI workflow that deploys into the folder, the lock.
    The page's picks become `options.conversion` of a `convert` run.
  - `deploy-convert.sh` works in modes: check, backup, move, link, compose, undo, drop-backup.
    Every move is written to `.deploy/convert-<release>/moves` before it happens. A run that
    stops halfway puts everything back (the `UndoConversion` cleanup step), and an
    `undo_convert` run does the same later, while the converted release is still live.
    The hard-link backup in `.deploy/convert-<release>/old` stays until it's removed from
    the run page, which needs a passkey.
  - A Docker stack keeps running by default; the next deploy recreates it from releases/.
- **Folder mappings** (`app/Actions/Folders`, `app/Platform/Sync`, `app/Livewire/Servers/Folders`):
  a folder on the Mac synced one way (push or pull) with a folder on a server, by rsync
  over the compiled ssh config.
  - `StartFolderSync` queues `SyncFolder` on `redis-deploys`, since only the worker mounts
    the project folders read-write. A partial unique index allows one active run per
    mapping. `RunFolderSync` runs rsync and reads its `--itemize-changes` output
    (`RsyncOutput`).
  - A preview is a dry run and changes nothing, not even the destination folder.
  - Deleting files that aren't in the source is off by default and needs a passkey. A run
    that deletes starts from a preview, and applying it passes `--max-delete` with the
    preview's count. Scheduled runs delete at most `deployme.sync.scheduled_max_delete`.
  - `FolderMappingRules` is checked on save and again before every run: Mac folders stay
    inside the project roots, and nothing is pushed into a reserved path (`/opt/infra`).
- **Folder browsers** (`Remote\FolderBrowser`, `Local\FolderPicker`) open at the folder the
  page gives them. In the browser the page's `wire:model` value wins over one the browser
  picks while mounting, so pages resolve an existing folder first (`RemoteFs::nearestFolder`).
- **Health and upkeep** (`app/Actions/Targets`, `app/Actions/Maintenance`, `app/Support`):
  - The scheduler queues `CheckHealth` on `redis-probes` for each deployed target that
    has a health URL or a Docker stack deploy.me runs. `CheckTargetHealth` runs the
    deploy's own health scripts from the server. `deploy-health.sh` asks the server's
    own edge first (the URL's host resolved to 127.0.0.1), so a CDN's bot challenge
    (Cloudflare's, on bm.example.dev) can't fail it. `unhealthy_since` marks when it
    began failing. A target with a run in progress is skipped. A result that differs from the
    last one broadcasts `TargetHealthChanged`.
  - Every check is also kept in `target_health_checks` (the server page's Checks tab),
    so a flapping app reads as a history, not one result.
  - `PruneOldData` runs daily at 03:30. It gzips a finished run's log lines into
    `storage/app/deploy-logs/{id}.log.gz` (`LogArchive`; the log download reads it), then
    deletes old archives, probe snapshots, health checks, audit entries and folder syncs
    (each mapping keeps its newest).
  - Intervals and retention live in the `settings` table (`Settings::int`; the allowed
    values are `Settings::CHOICES`), edited in Settings → Schedules.
  - `Support\Alert` sends a Telegram message when a run ends badly (failed, rolled back
    or stalled — never a good one) and when a target's health changes. The bot token and
    chat id are `Settings::string` values, the token encrypted (`Settings::SECRETS`),
    edited in Settings → Alerts with a test message. Nothing there may fail what it
    reports on: every call is wrapped in `rescue(…, report: false)` and `Alert::send()`
    logs and swallows whatever Telegram says.
  - The dashboard compares each target's live commit with its branch head (`Drift`: the
    local repo, the GitHub mirror, or the last CI run). `GreenBuild` is the cheaper
    question the projects list and the project page ask: has CI passed on a commit no
    server here runs yet?
- **Audit log** (`app/Support/Audit`, `app/Models/AuditEntry`, `app/Livewire/Audit`): who
  did what, to which object, when, and how it ended — never a value. Written for trusting a
  host key, breaking a lock, saving or removing a credential, removing a server, project or
  target, and by `AuditingSshClient` (which decorates `SshClient`) for every remote command.
  The table is append-only: a trigger refuses UPDATE, and DELETE only when the transaction
  sets `deployme.pruning`, which `PruneOldData` does. `server_id`, `project_id` and
  `user_id` carry no foreign keys — the log outlives what it's about.
- **MCP server** (`app/Mcp`, `deployme:mcp`, `docs/mcp.md`): deploy.me for AI agents over
  stdio, started by the client with `docker exec -i deployme-web php artisan deployme:mcp`.
  `Server` answers JSON-RPC (`initialize`, `ping`, `tools/list`, `tools/call`; a notification
  runs nothing), `Stdio` reads and writes one message per line, and each tool is a class in
  `Mcp/Tools` extending `Tool`. Read-only by default; tools whose `deploys()` is true
  (`deploy`, `rollback`, `cancel_deployment`) are listed only with `--allow-deploys`, and go
  through `StartDeployment`/`CancelDeployment` like the pages, as the single user with the
  trigger `mcp`. Answers never carry a token, an env file's contents or `APP_KEY`.

App: https://deployme.localhost (shared Caddy edge, `tls internal`).

## Conventions

- On this machine it follows `~/Personal/infra/PROJECT_CONVENTION.md` (shared stack, no own
  postgres/redis/caddy). `compose.standalone.yml` exists only for other people's machines
  and is never in COMPOSE_FILE here. Every service is prefixed `deployme-` (bare names
  collide on `proxy-network`).
- Queues: `redis` (interactive, default), `redis-deploys`, `redis-probes`. Keep each
  connection's `retry_after` above its worker `--timeout`
  (`tests/Feature/QueueConfigTest.php` enforces it). Deploys get two hours:
  worker `--timeout=7200` > `RunDeployment::TIMEOUT` (7000) > `StepTimeout::MAX`
  (3600), which caps every step timeout a project's settings can stretch, so a
  slow step fails saying what it waited for instead of taking the worker down.
- Project settings are project-level only; delivery uses an ordered strategy list.
- Secrets never go in argv or logs; remote scripts get them over stdin.
- Single local user (Fortify, no registration); sensitive actions use password confirm.
