<?php

use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\ProbeServer;
use App\Jobs\RunDeployment;
use App\Mcp\Server as McpServer;
use App\Mcp\Stdio;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\Target;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Calls a tool and decodes what it answered.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{error: bool, text: string, data: mixed}
 */
function mcpCall(McpServer $server, string $tool, array $arguments = []): array
{
    $answer = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    $result = (array) $answer['result'];
    $text = $result['content'][0]['text'];

    return ['error' => $result['isError'], 'text' => $text, 'data' => json_decode($text, true)];
}

function mcpTarget(): Target
{
    $server = Server::factory()->create(['slug' => 'box', 'ssh_alias' => 'box', 'name' => 'Box']);
    $project = Project::factory()->create(['slug' => 'demo', 'name' => 'Demo']);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

beforeEach(fn () => Queue::fake());

it('introduces itself and agrees on a protocol version', function () {
    $answer = (new McpServer)->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
    $result = (array) $answer['result'];

    expect($answer['id'])->toBe(1)
        ->and($result['protocolVersion'])->toBe('2025-03-26')
        ->and($result['serverInfo']['name'])->toBe('deploy.me')
        ->and($result['capabilities'])->toHaveKey('tools')
        ->and($result['instructions'])->toContain('read-only');

    $unknown = (array) (new McpServer)->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])['result'];
    expect($unknown['protocolVersion'])->toBe(McpServer::PROTOCOL_VERSIONS[0]);
});

it('answers pings, ignores notifications and refuses what it does not know', function () {
    $server = new McpServer;

    expect($server->handle(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping']))->toMatchArray(['id' => 'a'])
        ->and($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull()
        ->and($server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'sampling/createMessage'])['error']['code'])->toBe(-32601)
        ->and($server->handle(['id' => 4, 'method' => 'ping'])['error']['code'])->toBe(-32600)
        ->and(json_decode($server->handleLine('{not json'), true)['error']['code'])->toBe(-32700);
});

it('runs nothing a notification asks for', function () {
    mcpTarget();

    expect((new McpServer(allowDeploys: true))->handle(['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'deploy', 'arguments' => ['project' => 'demo']]]))->toBeNull()
        ->and(Deployment::query()->count())->toBe(0);
});

it('offers only the tools that read until deploys are allowed', function () {
    $names = fn (McpServer $server) => collect((array) $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result'])
        ->get('tools');

    $readOnly = collect($names(new McpServer))->pluck('name');
    $all = collect($names(new McpServer(allowDeploys: true)))->pluck('name');

    expect($readOnly)->toContain('list_projects', 'get_deployment', 'plan_deploy', 'probe_server')
        ->not->toContain('deploy', 'rollback', 'cancel_deployment')
        ->and($all)->toContain('deploy', 'rollback', 'cancel_deployment');

    $deploy = collect($names(new McpServer(allowDeploys: true)))->firstWhere('name', 'deploy');
    expect($deploy['annotations']['destructiveHint'])->toBeTrue()
        ->and($deploy['inputSchema']['required'])->toBe(['project']);

    $refused = mcpCall(new McpServer, 'deploy', ['project' => 'demo']);
    expect($refused['error'])->toBeTrue()->and($refused['text'])->toContain('--allow-deploys');
});

it('lists servers and projects with where each is deployed', function () {
    $target = mcpTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20260911-120000', 'commit_sha' => str_repeat('a', 40)]);
    $target->update(['current_release_id' => $release->id]);
    ServerSnapshot::query()->create(['server_id' => $target->server_id, 'captured_at' => now(), 'cpu_usage' => 12.5, 'mem_total' => 1000, 'mem_available' => 250]);

    $servers = mcpCall(new McpServer, 'list_servers')['data']['servers'];
    expect($servers)->toHaveCount(1)
        ->and($servers[0])->toMatchArray(['slug' => 'box', 'cpu_percent' => 12.5, 'memory_used_percent' => 75.0]);

    $projects = mcpCall(new McpServer, 'list_projects')['data']['projects'];
    expect($projects[0]['slug'])->toBe('demo')
        ->and($projects[0]['targets'][0]['server'])->toBe('box')
        ->and($projects[0]['targets'][0]['live_release']['commit'])->toBe(str_repeat('a', 40));
});

it('finds a server by alias or name, and says what there is when it cannot', function () {
    mcpTarget();

    expect(mcpCall(new McpServer, 'get_server', ['server' => 'Box'])['data']['slug'])->toBe('box');

    $miss = mcpCall(new McpServer, 'get_server', ['server' => 'nope']);
    expect($miss['error'])->toBeTrue()->and($miss['text'])->toContain('Servers: box');

    $missing = mcpCall(new McpServer, 'get_project', []);
    expect($missing['error'])->toBeTrue()->and($missing['text'])->toContain('“project” is required');
});

it('explains a run with its steps and the end of its log', function () {
    $target = mcpTarget();
    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed, 'outcome' => 'Health check failed.', 'log_lines' => 3]);
    $run->steps()->create(['key' => 'health', 'label' => 'Health check', 'position' => 1, 'status' => 'failed', 'detail' => '502 after 180 s']);

    foreach (['$ docker compose up -d', 'app  Started', 'curl: 502'] as $i => $line) {
        DB::table('deployment_logs')->insert(['deployment_id' => $run->id, 'seq' => $i + 1, 'step_key' => $i === 2 ? 'health' : 'compose', 'stream' => 'out', 'line' => $line, 'logged_at' => now()]);
    }

    $detail = mcpCall(new McpServer, 'get_deployment', ['run' => "#{$run->id}"])['data'];
    expect($detail)->toMatchArray(['run' => $run->id, 'status' => 'failed', 'outcome' => 'Health check failed.'])
        ->and($detail['steps'][0])->toMatchArray(['key' => 'health', 'status' => 'failed'])
        ->and($detail['log_tail'])->toBe(['[compose] $ docker compose up -d', '[compose] app  Started', '[health] curl: 502']);

    $page = mcpCall(new McpServer, 'get_deployment_log', ['run' => (string) $run->id, 'after' => 1, 'limit' => 1])['data'];
    expect($page['lines'])->toHaveCount(1)
        ->and($page['lines'][0]['line'])->toBe('app  Started')
        ->and($page['next_after'])->toBe(2);

    $health = mcpCall(new McpServer, 'get_deployment_log', ['run' => (string) $run->id, 'step' => 'health'])['data'];
    expect(array_column($health['lines'], 'line'))->toBe(['curl: 502']);

    expect(mcpCall(new McpServer, 'list_deployments', ['status' => 'failed'])['data']['deployments'][0]['run'])->toBe($run->id)
        ->and(mcpCall(new McpServer, 'list_deployments', ['status' => 'broken'])['error'])->toBeTrue();
});

it('finds a step’s log lines however many lines other steps wrote before them', function () {
    $target = mcpTarget();
    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed, 'log_lines' => 6002]);

    // A chatty upload, then the health check.
    $rows = array_map(fn (int $seq) => ['deployment_id' => $run->id, 'seq' => $seq, 'step_key' => 'upload', 'stream' => 'out', 'line' => "file {$seq}", 'logged_at' => now()], range(1, 6000));
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('deployment_logs')->insert($chunk);
    }
    DB::table('deployment_logs')->insert([
        ['deployment_id' => $run->id, 'seq' => 6001, 'step_key' => 'health', 'stream' => 'out', 'line' => 'curl: 502', 'logged_at' => now()],
        ['deployment_id' => $run->id, 'seq' => 6002, 'step_key' => 'health', 'stream' => 'out', 'line' => 'gave up', 'logged_at' => now()],
    ]);

    $health = mcpCall(new McpServer, 'get_deployment_log', ['run' => (string) $run->id, 'step' => 'health'])['data'];

    expect(array_column($health['lines'], 'line'))->toBe(['curl: 502', 'gave up'])
        ->and($health['next_after'])->toBe(6002);
});

it('deploys a project to its only server, as the person who uses deploy.me', function () {
    $user = User::factory()->create();
    mcpTarget()->project->update(['github_repo' => 'demo']);

    // GitHub says abc1234 is on main, behind its head.
    Credential::factory()->create();
    $head = str_repeat('f', 40);
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => $head]]),
        "api.github.com/repos/KielD-01/demo/compare/{$head}...abc1234*" => Http::response(['status' => 'behind']),
    ]);

    $result = mcpCall(new McpServer(allowDeploys: true), 'deploy', ['project' => 'demo', 'commit' => 'abc1234']);

    $run = Deployment::query()->sole();
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['started' => true, 'run' => $run->id, 'server' => 'box'])
        ->and($run->kind)->toBe(DeploymentKind::Deploy)
        ->and($run->trigger)->toBe('mcp')
        ->and($run->user_id)->toBe($user->id)
        ->and($run->options)->toBe(['commit' => 'abc1234']);
    Queue::assertPushed(RunDeployment::class);

    $again = mcpCall(new McpServer(allowDeploys: true), 'deploy', ['project' => 'demo']);
    expect($again['error'])->toBeTrue()->and($again['text'])->toContain('already queued or running');

    expect(mcpCall(new McpServer(allowDeploys: true), 'deploy', ['project' => 'demo', 'commit' => 'main; rm -rf /'])['error'])->toBeTrue();
});

it('asks which server when a project deploys to more than one', function () {
    $target = mcpTarget();
    Target::factory()->create(['project_id' => $target->project_id, 'server_id' => Server::factory()->create(['slug' => 'other'])->id]);

    $result = mcpCall(new McpServer(allowDeploys: true), 'deploy', ['project' => 'demo']);

    expect($result['error'])->toBeTrue()
        ->and($result['text'])->toContain('box, other')
        ->and(Deployment::query()->count())->toBe(0);
});

it('rolls back to the previous release, and only to one it can return to', function () {
    $target = mcpTarget();
    $old = Release::factory()->create(['target_id' => $target->id, 'name' => '20260910-120000']);
    $live = Release::factory()->create(['target_id' => $target->id, 'name' => '20260911-120000']);
    $target->update(['current_release_id' => $live->id, 'previous_release_id' => $old->id]);

    $releases = mcpCall(new McpServer, 'get_project', ['project' => 'Demo'])['data']['targets'][0]['releases'];
    expect(array_column($releases, 'name'))->toBe(['20260911-120000', '20260910-120000'])
        ->and($releases[0]['live'])->toBeTrue();

    expect(mcpCall(new McpServer(allowDeploys: true), 'rollback', ['project' => 'demo', 'release' => '20260911-120000'])['text'])->toContain('already live');

    $result = mcpCall(new McpServer(allowDeploys: true), 'rollback', ['project' => 'demo']);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['release'])->toBe('20260910-120000')
        ->and(Deployment::query()->sole()->options)->toBe(['release_id' => $old->id]);
});

it('cancels a queued run and refuses one that has ended', function () {
    $target = mcpTarget();
    $queued = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);

    expect(mcpCall(new McpServer(allowDeploys: true), 'cancel_deployment', ['run' => (string) $queued->id])['data']['status'])->toBe('cancelled')
        ->and(mcpCall(new McpServer(allowDeploys: true), 'cancel_deployment', ['run' => (string) $queued->id])['text'])->toContain('already ended');
});

it('queues a probe', function () {
    mcpTarget();

    expect(mcpCall(new McpServer, 'probe_server', ['server' => 'box'])['data']['queued'])->toBeTrue();
    Queue::assertPushed(ProbeServer::class);
});

it('reads the audit log without the commands unless asked', function () {
    Audit::record(AuditAction::HostKeyTrusted, ['subject' => 'box']);
    Audit::record(AuditAction::RemoteCommand, ['subject' => 'probe.sh']);

    expect(mcpCall(new McpServer, 'list_audit')['data']['entries'])->toHaveCount(1)
        ->and(mcpCall(new McpServer, 'list_audit', ['commands' => true])['data']['entries'])->toHaveCount(2);
});

it('speaks one message per line over stdio', function () {
    $input = fopen('php://memory', 'r+');
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])."\n\n".json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n".json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])."\n");
    rewind($input);
    $output = fopen('php://memory', 'r+');

    Stdio::serve(new McpServer, $input, $output);

    rewind($output);
    $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['id'])->toBe(1)
        ->and(json_decode($lines[1], true)['result']['tools'])->not->toBeEmpty();
});

it('lists its tools on the command line', function () {
    $this->artisan('deployme:mcp', ['--list' => true, '--allow-deploys' => true])
        ->expectsOutputToContain('rollback')
        ->assertSuccessful();
});

it('refuses a request whose id is neither a string nor a number', function (mixed $id) {
    $answer = (new McpServer)->handle(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'ping']);

    // JSON-RPC 2.0: such an id isn't echoed back, the answer's id is null.
    expect($answer['error']['code'] ?? null)->toBe(-32600)
        ->and($answer['id'])->toBeNull();
})->with([[['x' => 1]], [[1, 2]], [true]]);
