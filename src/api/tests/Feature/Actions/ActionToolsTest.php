<?php

use App\Actions\Deployments\StartAction;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Livewire\Console\Shell as ShellPane;
use App\Mcp\Server as McpServer;
use App\Models\Deployment;
use App\Models\User;
use App\Terminal\Output;
use App\Terminal\Shell;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const TOOL_ACTION = <<<'YAML'
    name: Reindex search
    mcp: true
    inputs:
      index: { type: choice, options: [products, orders, all], default: products }
      fresh: { type: boolean, description: flush the index first? }
      days: { type: number, default: 7 }
    steps:
      - { name: Flush the index, if: inputs.fresh, exec: api, run: php artisan scout:flush "$INDEX" }
      - { name: Import, one-off: worker, run: php artisan scout:import "$INDEX" }
    YAML;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{error: bool, text: string, data: mixed}
 */
function actionTool(McpServer $server, string $tool, array $arguments = []): array
{
    $result = (array) $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])['result'];

    return ['error' => $result['isError'], 'text' => $result['content'][0]['text'], 'data' => json_decode($result['content'][0]['text'], true)];
}

function toolText(Output $out): string
{
    return implode("\n", array_map(fn (array $line) => implode('', array_column($line, 0)), $out->lines));
}

it('lists actions to agents, and runs one only with deploys allowed', function () {
    $target = actionTarget();
    projectAction($target->project, TOOL_ACTION, 'reindex-search');

    $readOnly = new McpServer;
    $tools = array_column(json_decode(json_encode($readOnly->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])), true)['result']['tools'], 'name');
    $listed = actionTool($readOnly, 'list_actions', ['project' => 'demo'])['data']['actions'][0];

    expect($tools)->toContain('list_actions')->not->toContain('run_action')
        ->and($listed)->toMatchArray(['action' => 'reindex-search', 'open_to_agents' => true, 'targets' => [$target->server->slug]])
        ->and(array_column($listed['inputs'], 'env'))->toBe(['INDEX', 'FRESH', 'DAYS'])
        ->and(actionTool($readOnly, 'run_action', ['project' => 'demo', 'action' => 'reindex-search'])['text'])->toContain('needs the server started with --allow-deploys');

    $answer = actionTool(new McpServer(allowDeploys: true), 'run_action', ['project' => 'demo', 'action' => 'reindex-search', 'inputs' => ['index' => 'orders', 'fresh' => true]]);
    $run = Deployment::query()->where('kind', DeploymentKind::Action)->sole();

    expect($answer['error'])->toBeFalse()
        ->and($answer['data'])->toMatchArray(['started' => true, 'kind' => 'action', 'trigger' => 'mcp'])
        ->and($answer['data']['action'])->toMatchArray(['key' => 'reindex-search', 'inputs' => ['index' => 'orders', 'fresh' => 'yes', 'days' => '7']])
        ->and($run->options['inputs'])->toBe(['index' => 'orders', 'fresh' => true, 'days' => 7]);
});

it('runs nothing an agent isn’t allowed to start', function () {
    $target = actionTarget();
    projectAction($target->project, "name: Closed\nsteps: [ls]\n", 'closed');
    projectAction($target->project, "name: Guarded\nconfirm: passkey\nsteps: [ls]\n", 'guarded');
    $mcp = new McpServer(allowDeploys: true);

    expect(actionTool($mcp, 'run_action', ['project' => 'demo', 'action' => 'closed'])['text'])->toContain('isn’t open to agents')
        ->and(actionTool($mcp, 'run_action', ['project' => 'demo', 'action' => 'guarded'])['text'])->toContain('asks for a passkey')
        ->and(actionTool($mcp, 'run_action', ['project' => 'demo', 'action' => 'nope'])['text'])->toContain('demo has no action “nope”. Its actions: closed, guarded.')
        ->and(Deployment::query()->count())->toBe(0);
});

it('never hands an agent a run’s secret inputs', function () {
    $target = actionTarget();
    $action = projectAction($target->project, "name: Rotate\ninputs: { token: secret }\nsteps: [ls]\n", 'rotate');
    $run = app(StartAction::class)($action, $target, ['token' => 'agent-must-not-see'], $this->user);

    $answer = actionTool(new McpServer, 'get_deployment', ['run' => (string) $run->id]);

    expect($answer['text'])->not->toContain('agent-must-not-see')
        ->and($answer['data']['options'])->not->toHaveKey('secrets')
        ->and($answer['data']['action']['inputs'])->toBe(['token' => '••••••']);
});

it('lists a project’s actions and their runs in the shell', function () {
    $target = actionTarget();
    projectAction($target->project, TOOL_ACTION, 'reindex-search');
    $shell = app(Shell::class);

    expect(toolText($shell->run('actions demo')))->toContain('reindex-search', 'manual', 'never')
        ->and(toolText($shell->run('actions demo --history')))->toContain('No action of demo’s has run yet.')
        ->and($shell->complete('run rei'))->toBe(['run reindex-search']);
});

it('walks a run through its inputs, shows the plan and starts only on yes', function () {
    $target = actionTarget();
    projectAction($target->project, TOOL_ACTION, 'reindex-search');
    $shell = app(Shell::class);

    $ask = $shell->run('run reindex-search --on '.$target->server->slug.' days=3')->ask;
    expect($ask['question'])->toBe('index')->and(array_column($ask['options'], 'value'))->toBe(['products', 'orders', 'all']);

    $ask = $shell->answer($ask['state'], 'all')->ask;
    expect($ask['question'])->toBe('fresh — flush the index first?')->and(array_column($ask['options'], 'value'))->toBe(['no', 'yes']);

    $plan = $shell->answer($ask['state'], 'yes');
    expect(toolText($plan))->toContain('┌─ plan', 'Reindex search → '.$target->server->name, 'exec api', 'one-off worker', 'INDEX=all FRESH=yes DAYS=3', 'over stdin')
        ->and($plan->ask['type'])->toBe('confirm')
        ->and(Deployment::query()->count())->toBe(0);

    $started = $shell->answer($plan->ask['state'], 'y');
    $run = Deployment::query()->where('kind', DeploymentKind::Action)->sole();

    expect(toolText($started))->toContain('started #'.$run->id)
        ->and($started->open)->toBe(['kind' => 'tail', 'arg' => (string) $run->id])
        ->and($run->trigger)->toBe('console')
        ->and($run->options['inputs'])->toBe(['index' => 'all', 'fresh' => true, 'days' => 3]);
});

it('takes the defaults with --yes, still asks before it runs, and asks for a secret at the prompt', function () {
    $target = actionTarget();
    projectAction($target->project, "name: Rotate\ninputs: { token: secret, days: { type: number, default: 7 } }\nsteps: [ls]\n", 'rotate');
    $shell = app(Shell::class);

    expect(toolText($shell->run('run rotate token=typed-in-the-open')))->toContain('token is a secret: leave it off the command line');

    $ask = $shell->run('run rotate --yes')->ask;
    expect($ask)->toMatchArray(['type' => 'text', 'secret' => true, 'question' => 'token'])
        // What the question carries back is encrypted.
        ->and(json_encode($shell->answer($ask['state'], 'very-secret-token')->ask['state']))->not->toContain('very-secret-token');

    $pane = Livewire::test(ShellPane::class)->call('submit', 'run rotate --yes')->call('submit', 'very-secret-token');

    expect(json_encode($pane->get('screen'), JSON_UNESCAPED_UNICODE))->not->toContain('very-secret-token')->toContain('••••••')
        ->and($pane->get('ask')['type'])->toBe('confirm');
});

it('won’t run an action that asks for a passkey until you’ve confirmed one', function () {
    $target = actionTarget();
    projectAction($target->project, "name: Flush\nconfirm: passkey\nsteps: [ls]\n", 'flush');
    $shell = app(Shell::class);

    expect(toolText($shell->run('run flush')))->toContain('asks for your passkey');

    session(['auth.password_confirmed_at' => time()]);
    $plan = $shell->run('run flush');
    $shell->answer($plan->ask['state'], 'y');

    expect(Deployment::query()->where('kind', DeploymentKind::Action)->sole()->status)->toBe(DeploymentStatus::Queued);
});

it('says a target is busy rather than queueing an action behind it', function () {
    $target = actionTarget();
    projectAction($target->project, TOOL_ACTION, 'reindex-search');
    $deploy = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);
    $shell = app(Shell::class);

    $plan = $shell->run('run demo/reindex-search --yes');

    expect(toolText($shell->answer($plan->ask['state'], 'y')))->toContain("Deploy #{$deploy->id} is already going on");
});
