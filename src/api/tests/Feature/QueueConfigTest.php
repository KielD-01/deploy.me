<?php

use App\Deploy\Pipeline\Steps\RecreateServices;
use App\Deploy\Pipeline\StepTimeout;
use App\Jobs\RunDeployment;
use App\Jobs\SyncFolder;
use App\Models\Project;
use App\Support\QueueTimeouts;

// The supervisor program list lives in the image; on the host it's in core/.
function supervisorQueuesConf(): string
{
    foreach ([config('deployme.supervisor_queues_conf'), base_path('../../core/local/php/supervisor/conf.d/queues.conf')] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    throw new RuntimeException('queues.conf not found');
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
