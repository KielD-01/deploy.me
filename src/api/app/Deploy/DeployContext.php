<?php

namespace App\Deploy;

use App\Deploy\Conversion\Conversion;
use App\Deploy\Logging\StepLogger;
use App\Deploy\Pipeline\DeploymentCancelled;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\DeliveryStrategyKey;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\Exceptions\SshTimeout;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Support\DotEnv;
use Throwable;

/**
 * Everything a step needs: the deployment, where it goes, the log, and a way
 * to run deploy scripts on the server.
 */
final class DeployContext
{
    // A running deploy reports at least this often, even while a command is silent.
    private const HEARTBEAT_EVERY = 10.0;

    // What steps leave for later steps and for the runner.

    // The release this run deploys, switches to, adopts or converts, and the
    // one that was live before it.
    public ?Release $release = null;

    public ?Release $previous = null;

    public ?DeliveryStrategyKey $strategy = null;

    // The run holds the folder's lock.
    public bool $lease = false;

    // The new release (or the folder's new files) went live.
    public bool $live = false;

    // Where the deploy's own ghcr.io login is on the server, while it's there.
    public ?string $registryConfig = null;

    // The services a blue/green roll replaced; recreating leaves them be.
    /** @var list<string> */
    public array $rolled = [];

    // What the health gate said.
    public bool $healthy = false;

    public bool $unhealthy = false;

    // A conversion: its plan, whether items started moving, whether the
    // stack was restarted from the release, and whether it was all put back.
    public ?Conversion $conversion = null;

    public bool $moving = false;

    public bool $restarted = false;

    public bool $undone = false;

    // The converted release an undo run takes back.
    public ?Release $undoing = null;

    /** @var array{broken: bool, holder: string}|null */
    public ?array $brokenLock = null;

    // An action run, once ResolveAction has read it: the inputs' values,
    // the variables they become, the folder the steps run in and the env
    // file's variables.
    /** @var array{values?: array<string, mixed>, env?: array<string, string>, dir?: string, env_file?: array<string, string>} */
    public array $action = [];

    // Action steps that failed and were carried past (continue-on-error).
    public int $carriedOn = 0;

    // Set by the runner before the cleanup steps: every main step ran, or
    // the run failed, or it was cancelled. A switch back sets rolledBack.
    public bool $completed = false;

    public bool $failed = false;

    public bool $cancelled = false;

    public ?Release $rolledBack = null;

    /**
     * Pre-flight's note of what an earlier deployment left in the folder
     * ('foreign'), until it has a property of its own.
     *
     * @var array<string, mixed>
     */
    public array $memo = [];

    private float $heartbeatAt = 0.0;

    private float $cancelCheckedAt = 0.0;

    // Set once the cleanup steps start: from then on nothing stops the run.
    private bool $shielded = false;

    /** @var list<callable> */
    private array $finishers = [];

    public function __construct(
        public readonly Deployment $deployment,
        public readonly Target $target,
        public readonly Project $project,
        public readonly Server $server,
        public readonly RemoteLayout $layout,
        public readonly StepLogger $log,
        private readonly SshClient $ssh,
    ) {}

    public static function for(Deployment $deployment, SshClient $ssh): self
    {
        $target = $deployment->target()->with(['project', 'server'])->firstOrFail();
        $log = new StepLogger($deployment);

        // Every kind of run, not only the ones that write the env files: what
        // a rollback or a conversion runs can print them just the same.
        foreach ($target->project->envFiles()->get() as $file) {
            foreach (DotEnv::parse($file->content) as $key => $value) {
                if (DotEnv::isSecret($key)) {
                    $log->redactor->add($value);
                }
            }
        }

        return new self($deployment, $target, $target->project, $target->server, $target->layout(), $log, $ssh);
    }

    /**
     * Runs a script from resources/ssh-scripts on the server. Its output goes
     * to the log as it arrives; ::deployme::key=value markers are collected
     * instead of logged. It runs in a session of its own and notes its
     * process group, so when a cancel or a timeout makes deploy.me let go of
     * it, what it started on the server is stopped too.
     *
     * @param  array<string, string|int>  $vars
     */
    public function script(string $name, array $vars = [], int $timeout = StepTimeout::MEDIUM): ScriptRun
    {
        $markers = [];
        $pgidFile = $this->layout->runs()."/{$this->deployment->id}.pgid";

        try {
            $result = $this->ssh->run(
                $this->server->ssh_alias,
                RemoteScript::file($name, [...$vars, 'PGID_FILE' => $pgidFile])->inOwnSession(),
                function (string $line, string $stream) use (&$markers): void {
                    if (preg_match('/^::deployme::([a-z_]+)=(.*)$/', $line, $match)) {
                        $markers[$match[1]] = $match[2];

                        return;
                    }

                    $this->log->line($line, $stream);
                },
                $timeout,
                fn () => $this->tick(),
            );
        } catch (DeploymentCancelled|SshTimeout $e) {
            $this->stopOnServer($pgidFile);

            throw $e;
        }

        return new ScriptRun($result, $markers);
    }

    /**
     * The ssh session is closed, but what the script started may still run
     * on the server: its process group gets TERM, then KILL. It never fails
     * the run; at worst what runs there keeps running.
     */
    private function stopOnServer(string $pgidFile): void
    {
        try {
            $this->ssh->run(
                $this->server->ssh_alias,
                RemoteScript::file('deploy-kill', ['FILE' => $pgidFile]),
                fn (string $line, string $stream) => $this->log->line($line, $stream),
                StepTimeout::QUICK,
            );
        } catch (Throwable $e) {
            $this->log->note('! Couldn’t stop what the script left running on the server: '.($e instanceof SshException ? $e->userMessage() : $e->getMessage()));
        }
    }

    /**
     * Heartbeat and cancellation check; cheap to call often.
     *
     * @throws DeploymentCancelled
     */
    public function tick(): void
    {
        $now = microtime(true);

        if ($now - $this->heartbeatAt >= self::HEARTBEAT_EVERY) {
            $this->heartbeatAt = $now;
            Deployment::query()->whereKey($this->deployment->id)->update(['heartbeat_at' => now()]);
            $this->log->saveCount();
        }

        if ($now - $this->cancelCheckedAt >= (int) config('deployme.deploy.cancel_check_ms', 2000) / 1000) {
            $this->cancelCheckedAt = $now;
            $this->throwIfCancelled();
        }

        $this->log->flush();
    }

    /**
     * @throws DeploymentCancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->shielded) {
            return;
        }

        if (Deployment::query()->whereKey($this->deployment->id)->whereNotNull('cancel_requested_at')->exists()) {
            throw new DeploymentCancelled;
        }
    }

    /**
     * The cleanup steps are next: a cancel can't stop them. They give the lock
     * back and switch back to the release that was live, and a cancel that
     * killed them halfway would leave exactly the mess cancelling avoids.
     */
    public function shield(): void
    {
        $this->shielded = true;
    }

    /**
     * Something to do in the worker once the run is over, however it ended:
     * removing a temporary export, say.
     */
    public function onFinish(callable $callback): void
    {
        $this->finishers[] = $callback;
    }

    public function finish(): void
    {
        foreach ($this->finishers as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->finishers = [];
    }
}
