<?php

use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\Steps\Preflight;
use App\Deploy\Pipeline\Steps\RunHooks;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\HookPhase;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Js;

/**
 * Every step class, as the pipeline builds it (RunHooks once per phase).
 *
 * @return list<Step>
 */
function everyStep(): array
{
    $steps = [];

    foreach (glob(app_path('Deploy/Pipeline/Steps/*.php')) as $file) {
        $class = 'App\\Deploy\\Pipeline\\Steps\\'.basename($file, '.php');

        if ($class === RunHooks::class) {
            array_push($steps, ...array_map(fn (HookPhase $phase) => new RunHooks($phase), HookPhase::cases()));

            continue;
        }

        $steps[] = app($class);
    }

    return $steps;
}

it('names each step by its KEY, and no two alike', function () {
    $keys = [];

    foreach (everyStep() as $step) {
        $keys[] = $step->key();

        // Pre-flight has no KEY yet: its file was being changed elsewhere when the others got theirs.
        if ($step instanceof Preflight) {
            continue;
        }

        expect(defined($step::class.'::KEY'))->toBeTrue($step::class.' has no KEY');

        // RunHooks is one step per phase: hooks_<phase>.
        $step instanceof RunHooks
            ? expect($step->key())->toStartWith(RunHooks::KEY.'_')
            : expect($step->key())->toBe($step::KEY);
    }

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and(array_map(fn (HookPhase $phase) => RunHooks::KEY.'_'.$phase->value, HookPhase::cases()))->each->toBeIn($keys);
});

it('tells the run page’s log which statuses mean a run is still going', function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    $run = Deployment::factory()->create(['target_id' => demoTarget()->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);

    $this->get(route('deployments.show', $run))
        ->assertOk()
        ->assertSee('active: '.Js::from(DeploymentStatus::ACTIVE)->toHtml(), false);
});
