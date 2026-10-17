<?php

use App\Enums\DeploymentKind;
use App\Livewire\Console\Git;
use App\Livewire\Console\Htop;
use App\Livewire\Console\Session;
use App\Livewire\Console\Status;
use App\Livewire\Console\Tail;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('tails the newest run, its steps and its log', function () {
    $target = Target::factory()->create();
    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'commit_sha' => str_repeat('b', 40)]);
    $run->steps()->create(['key' => 'migrate', 'label' => 'Migrate', 'position' => 1, 'status' => 'running']);
    DB::table('deployment_logs')->insert(['deployment_id' => $run->id, 'seq' => 1, 'step_key' => 'migrate', 'stream' => 'out', 'line' => 'Running migrations.', 'logged_at' => now()]);

    Livewire::test(Tail::class)
        ->assertSee('#'.$run->id)
        ->assertSee('migrate')
        ->call('linesAfter', 0)
        ->assertReturned(fn (array $lines) => $lines[0][4] === 'Running migrations.');
});

it('shows a server as htop: meters, busiest processes, stacks', function () {
    $server = Server::factory()->create(['slug' => 'prod-eu', 'status' => 'online']);
    ServerSnapshot::query()->create([
        'server_id' => $server->id, 'captured_at' => now(), 'cpu_usage' => 50.0, 'mem_total' => 1000, 'mem_available' => 400,
        'disks' => [['source' => '/dev/sda1', 'fstype' => 'ext4', 'size' => 100, 'used' => 40, 'available' => 60, 'mount' => '/']],
        'stacks' => [['Name' => 'shop', 'Status' => 'running(3)']],
        'processes' => ['cpu' => [['pid' => 1, 'name' => 'php', 'container' => 'shop-web-1', 'cpu' => 61.2]], 'mem' => [['pid' => 2, 'name' => 'postgres', 'container' => null, 'rss' => 2048]], 'net' => []],
    ]);

    Livewire::test(Htop::class, ['arg' => 'prod-eu'])
        ->assertSee('prod-eu')
        ->assertSee('||||||||||||||', false)
        ->assertSee('61.2')
        ->assertSee('shop-web-1')
        ->assertSee('shop');
});

it('shows a project’s branch with where each commit is live', function () {
    $repo = tempGitRepo(['README.md' => 'one']);
    mkdir("{$repo}/database/migrations", 0777, true);
    commitFile($repo, 'database/migrations/2026_10_17_000000_add.php', '<?php');
    $head = trim((string) shell_exec('git -C '.escapeshellarg($repo).' rev-parse HEAD'));
    config(['deployme.project_roots' => [dirname($repo)]]);

    $project = Project::factory()->local($repo)->create(['slug' => 'warehouse', 'branch' => trim((string) shell_exec('git -C '.escapeshellarg($repo).' branch --show-current'))]);
    $target = Target::factory()->create(['project_id' => $project->id, 'server_id' => Server::factory()->create(['slug' => 'prod-eu'])->id]);
    $release = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => $head]);
    $target->update(['current_release_id' => $release->id]);

    Livewire::test(Git::class, ['arg' => 'warehouse'])
        ->assertSee(substr($head, 0, 7))
        ->assertSee('prod-eu ● live')
        ->assertSee('+migration');
});

it('keeps a status pane', function () {
    Target::factory()->create();

    Livewire::test(Status::class)->assertSee('1 targets');
});

it('draws the frame first: the shell at once, the panes with data right after', function () {
    $page = $this->get(route('dashboard'))->assertOk();

    $page->assertSeeHtml('class="term-input"')->assertSee(['$ tail', '$ htop', '$ git']);
    expect(substr_count($page->getContent(), 'aria-busy="true"'))->toBe(3);
});

it('loads the commits on a project page after the page', function () {
    $project = Project::factory()->create(['slug' => 'warehouse']);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('$ git warehouse')
        ->assertSeeHtml('aria-busy="true"');
});

it('puts each commit of the branch on a line of its own', function () {
    $repo = tempGitRepo(['README.md' => 'one']);
    commitFile($repo, 'README.md', 'two');
    mkdir("{$repo}/database/migrations", 0777, true);
    commitFile($repo, 'database/migrations/2026_10_17_000000_add.php', '<?php');
    config(['deployme.project_roots' => [dirname($repo)]]);
    Project::factory()->local($repo)->create(['slug' => 'warehouse', 'branch' => trim((string) shell_exec('git -C '.escapeshellarg($repo).' branch --show-current'))]);

    $text = html_entity_decode(strip_tags(Livewire::test(Git::class, ['arg' => 'warehouse'])->html()));
    $rows = array_values(array_filter(explode("\n", $text), fn (string $line) => str_contains($line, '●')));

    // Three commits, three rows: the one that adds a migration ends its row too.
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toContain('+migration');
});

it('keeps what a pane shows in the server’s hands', function (string $component, string $property, mixed $value) {
    $pane = Livewire::test($component, $component === Session::class ? [] : ['pane' => 'p1']);

    expect(fn () => $pane->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    [Tail::class, 'deploymentId', 99],
    [Git::class, 'projectId', 99],
    [Git::class, 'page', true],
    [Htop::class, 'serverId', 99],
    [Session::class, 'layout', ['broken']],
]);
