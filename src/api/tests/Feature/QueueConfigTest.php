<?php

use App\Deploy\Pipeline\Steps\RecreateServices;
use App\Deploy\Pipeline\StepTimeout;
use App\Jobs\RunDeployment;
use App\Jobs\SyncFolder;
use App\Models\Project;
use App\Support\QueueTimeouts;

// The supervisor program list lives in the image; on the host it's in core/.
// A slim image runs queues-slim.conf as conf.d/queues.conf and keeps the full
// flavour's list as queues-full.conf, so these tests read both in either.
function supervisorQueuesConf(): string
{
    foreach (['/etc/supervisor/queues-full.conf', config('deployme.supervisor_queues_conf'), base_path('../../core/local/php/supervisor/conf.d/queues.conf')] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    throw new RuntimeException('queues.conf not found');
}

function slimSupervisorQueuesConf(): string
{
    foreach (['/etc/supervisor/queues-slim.conf', base_path('../../core/local/php/supervisor/queues-slim.conf')] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    throw new RuntimeException('queues-slim.conf not found');
}

/**
 * config/queue.php as it reads with DEPLOYME_QUEUE_DRIVER set to the given driver.
 *
 * @return array<string, mixed>
 */
function queueConfigWith(string $driver): array
{
    // Laravel's env() reads $_SERVER first, then $_ENV.
    $server = $_SERVER;
    $env = $_ENV;
    $_SERVER['DEPLOYME_QUEUE_DRIVER'] = $_ENV['DEPLOYME_QUEUE_DRIVER'] = $driver;

    try {
        return require config_path('queue.php');
    } finally {
        $_SERVER = $server;
        $_ENV = $env;
    }
}

it('runs a worker for every queue connection deploy.me uses', function () {
    $connections = array_column(QueueTimeouts::workers(supervisorQueuesConf()), 'connection');

    expect($connections)->toContain('redis', 'redis-deploys', 'redis-probes');
});

it('keeps retry_after above every worker timeout', function () {
    expect(QueueTimeouts::violations(supervisorQueuesConf()))->toBe([]);
});

it('keeps every deploy step inside the deploy job’s budget', function () {
    $worker = collect(QueueTimeouts::workers(supervisorQueuesConf()))->firstWhere('connection', 'redis-deploys');
    $slowToStop = Project::factory()->make([
        'compose_files' => ['compose.yaml'],
        // An ingest worker Compose waits two and a half hours for.
        'detection' => ['compose' => [['file' => 'compose.yaml', 'stop_grace' => ['ingest' => 9000]]]],
    ]);

    expect($worker['timeout'])->toBeGreaterThan(RunDeployment::TIMEOUT)
        ->and(RunDeployment::TIMEOUT)->toBeGreaterThan(StepTimeout::MAX)
        // Nothing a project's own settings reach stretches past the budget.
        ->and(StepTimeout::cap(99_999))->toBe(StepTimeout::MAX)
        ->and(RecreateServices::timeout($slowToStop))->toBe(StepTimeout::MAX);
});

it('keeps every named step timeout inside the deploy job’s budget', function () {
    $timeouts = (new ReflectionClass(StepTimeout::class))->getConstants();

    expect($timeouts)->toHaveKeys(['QUICK', 'SHORT', 'CHECK', 'MEDIUM', 'PRUNE', 'TRANSFER', 'LONG', 'MAX'])
        ->and(max($timeouts))->toBe(StepTimeout::MAX)
        ->and(max($timeouts))->toBeLessThan(RunDeployment::TIMEOUT);
});

it('gives folder syncs a worker of their own, so a long sync never holds up a deploy', function () {
    preg_match_all('/queue:work (\S+) --queue=(\S+)/', (string) file_get_contents(supervisorQueuesConf()), $matches, PREG_SET_ORDER);
    $served = collect($matches)->flatMap(fn (array $m) => array_map(fn (string $queue) => "{$m[1]}/{$queue}", explode(',', $m[2])))->all();
    $queueOf = fn (object $job) => "{$job->connection}/{$job->queue}";

    $sync = new SyncFolder(1);
    $deploy = new RunDeployment(1);

    expect($served)->toContain($queueOf($sync), $queueOf($deploy))
        ->and($queueOf($sync))->not->toBe($queueOf($deploy));
});

it('runs four workers for the probes and health checks every minute brings', function () {
    $conf = (string) file_get_contents(supervisorQueuesConf());

    preg_match('/\[program:probes\].*?numprocs=(\d+)/s', $conf, $match);

    expect((int) ($match[1] ?? 0))->toBe(4);
});

it('gives the slim flavour the same programs and timeouts, with fewer workers', function () {
    $full = QueueTimeouts::workers(supervisorQueuesConf());
    $slim = QueueTimeouts::workers(slimSupervisorQueuesConf());
    $conf = (string) file_get_contents(slimSupervisorQueuesConf());
    preg_match_all('/numprocs=(\d+)/', $conf, $counts);

    expect($slim)->toBe($full)
        ->and(QueueTimeouts::violations(slimSupervisorQueuesConf()))->toBe([])
        ->and(array_sum(array_map(intval(...), $counts[1])))->toBeLessThanOrEqual(6)
        ->and($conf)->toContain('--queue=syncs');
});

it('runs the queue connections on Valkey in the full flavour and on the database in the slim one', function () {
    $connections = ['redis', 'redis-deploys', 'redis-probes'];
    $full = queueConfigWith('redis')['connections'];
    $slim = queueConfigWith('database')['connections'];

    foreach ($connections as $name) {
        expect($full[$name]['driver'])->toBe('redis')
            ->and($slim[$name]['driver'])->toBe('database')
            ->and($slim[$name]['table'])->toBe('jobs')
            // The same queue names and retry_after, whichever driver runs them.
            ->and($slim[$name]['queue'])->toBe($full[$name]['queue'])
            ->and($slim[$name]['retry_after'])->toBe($full[$name]['retry_after'])
            ->and($slim[$name]['after_commit'])->toBeTrue();
    }
});
