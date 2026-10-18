<?php

use App\Actions\Deployments\DetectStalledDeployments;
use App\Actions\Deployments\DispatchScheduledActions;
use App\Actions\Folders\DispatchDueFolderSyncs;
use App\Actions\Maintenance\CheckForUpdate;
use App\Actions\Maintenance\PruneOldData;
use App\Actions\Projects\DispatchDueCiPolls;
use App\Actions\Servers\DispatchDueProbes;
use App\Actions\Targets\DispatchDueHealthChecks;
use App\Jobs\RefreshDrift;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Proof of life for `deployme:doctor`. The scheduler runs in deployme-worker
// under deployme-supervise; if this key goes stale, the scheduler has stopped.
Schedule::call(fn () => Cache::put('deployme:scheduler:heartbeat', now()->getTimestamp(), now()->addMinutes(10)))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Queue a probe for every server whose probe interval has elapsed.
Schedule::call(fn () => app(DispatchDueProbes::class)())
    ->everyMinute()
    ->name('dispatch-server-probes')
    ->withoutOverlapping();

// Refresh GitHub Actions state for projects whose last check is older than
// deployme.ci.poll_interval.
Schedule::call(fn () => app(DispatchDueCiPolls::class)())
    ->everyMinute()
    ->name('dispatch-ci-polls')
    ->withoutOverlapping();

// Scheduled folder syncs (hourly, daily) that are due.
Schedule::call(fn () => app(DispatchDueFolderSyncs::class)())
    ->everyMinute()
    ->name('dispatch-folder-syncs')
    ->withoutOverlapping();

// How far each live target is behind its branch (git, off the dashboard's request).
Schedule::call(fn () => RefreshDrift::dispatch())
    ->everyMinute()
    ->name('refresh-drift')
    ->withoutOverlapping();

// Runs whose worker stopped reporting are marked stalled, so the target frees up.
Schedule::call(fn () => app(DetectStalledDeployments::class)())
    ->everyMinute()
    ->name('detect-stalled-deployments')
    ->withoutOverlapping();

// Project actions whose schedule came due (a busy target gets them a minute later).
Schedule::call(fn () => app(DispatchScheduledActions::class)())
    ->everyMinute()
    ->name('dispatch-scheduled-actions')
    ->withoutOverlapping();

// Health checks of deployed targets, as often as Settings → Schedules says.
Schedule::call(fn () => app(DispatchDueHealthChecks::class)())
    ->everyMinute()
    ->name('dispatch-health-checks')
    ->withoutOverlapping();

// Old run logs into archives; old archives, probe history and syncs out.
Schedule::call(fn () => app(PruneOldData::class)())
    ->dailyAt('03:30')
    ->name('prune-old-data')
    ->withoutOverlapping();

// Is there a newer deploy.me release? One anonymous GET to GitHub a day, unless
// Settings → Updates says off. Applying it is bin/update on the host.
Schedule::call(fn () => app(CheckForUpdate::class)())
    ->dailyAt('06:43')
    ->name('check-for-update')
    ->withoutOverlapping();
