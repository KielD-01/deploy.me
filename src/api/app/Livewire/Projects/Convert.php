<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\Conversion\Conversion;
use App\Deploy\Conversion\ConversionPlanner;
use App\Deploy\DeploymentBusy;
use App\Enums\DeploymentKind;
use App\Jobs\ReadTargetFolder;
use App\Models\Project;
use App\Models\Release;
use App\Models\Target;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Converting a folder an earlier deployment left in place: deploy.me reads
 * it and suggests what goes into the first release, what into shared/ and
 * what stays; you adjust that, and the conversion runs like a deploy. Nothing
 * is deleted, and until the next deploy, undo puts every item back.
 */
class Convert extends Component
{
    // A read older than this is done again when the page opens.
    private const FRESH_FOR = 600;

    public Project $project;

    #[Locked]
    public int $targetId;

    /** @var array<string, string> path => the destination you picked */
    public array $choices = [];

    public bool $restart = false;

    public ?string $problem = null;

    public function mount(Project $project, Target $target): void
    {
        abort_unless($target->project_id === $project->id && $target->archived_at === null, 404);

        $this->project = $project;
        $this->targetId = $target->id;

        if (! $target->canConvert()) {
            $this->redirectRoute('projects.show', $project, navigate: true);

            return;
        }

        if ($this->stale($target)) {
            ReadTargetFolder::start($target);
        }
    }

    #[Computed]
    public function target(): Target
    {
        return Target::query()->with(['server', 'project', 'currentRelease'])->findOrFail($this->targetId);
    }

    // The read finished (in the worker): show what it found.
    #[On('echo-private:projects.{project.id},.folder.read')]
    public function refreshRead(): void
    {
        unset($this->target);
    }

    public function readAgain(): void
    {
        $this->reset('problem');
        ReadTargetFolder::start($this->target);
        unset($this->target);
    }

    public function pick(string $path, string $to): void
    {
        $row = collect($this->plan()['rows'])->firstWhere('path', $path);

        if ($row !== null && in_array($to, $row['options'], true)) {
            $this->choices[$path] = $to;
        }
    }

    public function convert(StartDeployment $start): void
    {
        $this->reset('problem');
        $target = $this->target;

        if ($this->reading($target) || ! is_array($target->folder_scan['items'] ?? null)) {
            $this->problem = 'Wait until the folder has been read.';

            return;
        }

        $plan = $this->plan();

        if (! ConversionPlanner::ready($plan)) {
            $this->problem = 'Fix the failed checks first.';

            return;
        }

        $conversion = ConversionPlanner::conversion($target->project, $plan, $this->restart);

        try {
            $run = $start($target, DeploymentKind::Convert, auth()->user(), ['conversion' => $conversion->toArray()]);
        } catch (DeploymentBusy $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->redirectRoute('deployments.show', $run, navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        return app(ConversionPlanner::class)->plan($this->target, $this->target->folder_scan ?? [], $this->choices);
    }

    private function reading(Target $target): bool
    {
        $since = $target->folder_scan['reading'] ?? null;

        return is_string($since) && Carbon::parse($since)->gt(now()->subMinutes(15));
    }

    /**
     * Never read, read without comparing to the repository, or read a while ago.
     */
    private function stale(Target $target): bool
    {
        $scan = $target->folder_scan ?? [];

        if ($this->reading($target)) {
            return false;
        }

        if (! isset($scan['items'], $scan['read_at'])) {
            return true;
        }

        $compared = array_key_exists('comparison', $scan) || ($scan['git'] ?? null) !== null || $scan['items'] === [];

        return ! $compared || Carbon::parse($scan['read_at'])->lt(now()->subSeconds(self::FRESH_FOR));
    }

    public function render(): View
    {
        $target = $this->target;
        $scan = $target->folder_scan ?? [];
        $plan = is_array($scan['items'] ?? null) && $scan['items'] !== [] ? $this->plan() : null;
        $rows = collect($plan['rows'] ?? []);

        return view('livewire.projects.convert', [
            'target' => $target,
            'server' => $target->server,
            'scan' => $scan,
            'plan' => $plan,
            'reading' => $this->reading($target),
            'ready' => $plan !== null && ConversionPlanner::ready($plan),
            'ci' => collect($plan['checks'] ?? [])->first(fn (array $check) => isset($check['workflow']) && $check['state'] === 'fail'),
            'intoRelease' => $rows->filter(fn (array $row) => ! $row['nested'] && $row['to'] === Conversion::RELEASE)->values(),
            'intoShared' => $rows->filter(fn (array $row) => $row['to'] === Conversion::SHARED)->values(),
            'staying' => $rows->filter(fn (array $row) => ! $row['nested'] && $row['to'] === Conversion::STAYS)->values(),
            // The release is named when the conversion runs; this is what it looks like.
            'releaseName' => Release::nextName($target),
        ])->title('Convert '.$target->path);
    }
}
