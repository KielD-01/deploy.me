<?php

use App\Actions\Transfer\ExportProjects;
use App\Actions\Transfer\ImportProjects;
use App\Enums\AuditAction;
use App\Enums\CiState;
use App\Enums\HookPhase;
use App\Enums\HookRunIn;
use App\Enums\ReleaseStatus;
use App\Jobs\PollCiStatus;
use App\Livewire\Settings\Index;
use App\Livewire\Settings\Transfer;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use App\Support\Transfer\TransferCode;
use App\Support\Transfer\TransferCodeInvalid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const TRANSFER_ACTION = <<<'YAML'
    name: Clear caches
    steps:
      - { name: Clear, exec: api, run: php artisan optimize:clear }
    YAML;

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

/**
 * A project the way a machine that has deployed it for a while holds it: a
 * target on "box" with a live release, the one before it, and a failed one.
 */
function transferProject(): Target
{
    $target = demoTarget([
        'name' => 'Shop', 'slug' => 'shop', 'github_owner' => 'KielD-01', 'github_repo' => 'shop',
        'health_url' => 'https://shop.example.dev/up', 'auto_deploy' => true, 'blue_green' => true,
        'image_templates' => [['var' => 'API_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/{owner}/{repo}', 'tag' => '{sha}']],
        'ci_state' => CiState::Passing,
    ]);
    $project = $target->ofProject();
    $project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_KEY=base64:secret\nDB_PASSWORD=hunter2\n")]);
    $project->hooks()->create(['phase' => HookPhase::AfterActivate, 'name' => 'Warm cache', 'command' => 'php artisan optimize', 'run_in' => HookRunIn::Host, 'timeout' => 120, 'continue_on_error' => true, 'position' => 1]);
    projectAction($project, TRANSFER_ACTION, 'clear-caches');

    $previous = Release::factory()->create(['target_id' => $target->id, 'name' => '20261017-100000', 'image_pins' => ['API_IMAGE' => 'ghcr.io/kield-01/shop:aaa']]);
    $live = Release::factory()->create(['target_id' => $target->id, 'name' => '20261018-100000', 'color' => 'green']);
    Release::factory()->create(['target_id' => $target->id, 'name' => '20261018-110000', 'status' => ReleaseStatus::Failed]);
    $target->update(['current_release_id' => $live->id, 'previous_release_id' => $previous->id, 'bootstrapped_at' => now()->subWeek(), 'last_deployed_at' => now()->subHour()]);

    return $target->fresh();
}

/**
 * What the other machine has before it imports: the same servers (they come
 * from ~/.ssh/config), and none of the projects.
 */
function forgetProjects(): void
{
    DB::table('targets')->update(['current_release_id' => null, 'previous_release_id' => null]);
    Release::query()->delete();
    Target::query()->delete();
    Project::query()->each(fn (Project $project) => $project->delete());
}

it('seals a code that only its passphrase opens', function () {
    $payload = ['format' => TransferCode::FORMAT, 'version' => 1, 'projects' => [['slug' => 'shop', 'note' => 'ключ']]];
    $code = TransferCode::seal($payload, 'correct horse battery');

    expect($code)->toStartWith('deployme1:')
        ->and($code)->not->toContain('shop')
        ->and(TransferCode::open(wordwrap($code, 60, "\n", true), 'correct horse battery'))->toBe($payload)
        ->and(fn () => TransferCode::open($code, 'wrong horse battery'))->toThrow(TransferCodeInvalid::class, 'Wrong passphrase')
        ->and(fn () => TransferCode::open(substr($code, 0, -3).'AAA', 'correct horse battery'))->toThrow(TransferCodeInvalid::class, 'Wrong passphrase')
        ->and(fn () => TransferCode::open(substr($code, 0, 30), 'correct horse battery'))->toThrow(TransferCodeInvalid::class, 'cut short')
        ->and(fn () => TransferCode::open('ghp_something', 'correct horse battery'))->toThrow(TransferCodeInvalid::class, 'isn’t a deploy.me transfer code');
});

it('refuses a code from a newer deploy.me', function () {
    $code = TransferCode::seal(['format' => TransferCode::FORMAT, 'version' => 2, 'projects' => []], 'correct horse battery');

    expect(fn () => TransferCode::open($code, 'correct horse battery'))->toThrow(TransferCodeInvalid::class, 'newer deploy.me');
});

it('exports what the other machine needs and nothing it doesn’t', function () {
    $target = transferProject();
    $payload = app(ExportProjects::class)();
    $shop = $payload['projects'][0];

    expect($shop)->toMatchArray(['slug' => 'shop', 'name' => 'Shop', 'source' => 'github', 'health_url' => 'https://shop.example.dev/up', 'auto_deploy' => true, 'blue_green' => true])
        ->and($shop)->not->toHaveKeys(['id', 'credential_id', 'ci_state', 'ci_run', 'ci_etag'])
        ->and($shop['env_files'])->toBe([['path' => '.env', 'role' => 'app', 'content' => "APP_KEY=base64:secret\nDB_PASSWORD=hunter2\n"]])
        ->and($shop['hooks'][0])->toMatchArray(['phase' => 'after_activate', 'name' => 'Warm cache', 'run_in' => 'host', 'continue_on_error' => true])
        ->and($shop['actions'][0])->toMatchArray(['key' => 'clear-caches', 'source' => 'app', 'name' => 'Clear caches'])
        ->and($shop['targets'])->toHaveCount(1)
        ->and($shop['targets'][0])->toMatchArray(['server' => 'box', 'path' => $target->path, 'current' => '20261018-100000', 'previous' => '20261017-100000'])
        // The failed release is nothing to go back to.
        ->and(array_column($shop['targets'][0]['releases'], 'name'))->toBe(['20261017-100000', '20261018-100000'])
        ->and($shop['targets'][0]['releases'][0]['image_pins'])->toBe(['API_IMAGE' => 'ghcr.io/kield-01/shop:aaa']);
});

it('writes local folders home-relative, and reads them against this machine’s home', function () {
    config(['deployme.host_home' => '/Users/old']);
    Project::factory()->local('/Users/old/Claude/blog')->create(['slug' => 'blog']);

    $payload = app(ExportProjects::class)();
    expect($payload['projects'][0]['local_path'])->toBe('~/Claude/blog');

    forgetProjects();
    config(['deployme.host_home' => '/Users/new']);
    app(ImportProjects::class)($payload);

    expect(Project::query()->where('slug', 'blog')->sole()->local_path)->toBe('/Users/new/Claude/blog');
});

it('recreates a project with its env files, hooks, actions, targets and releases', function () {
    $target = transferProject();
    $credential = Credential::factory()->create();
    $payload = app(ExportProjects::class)();
    $path = $target->path;
    forgetProjects();

    $result = app(ImportProjects::class)($payload);

    $project = Project::query()->where('slug', 'shop')->sole();
    $imported = $project->targets()->sole();

    expect($result)->toBe(['projects' => ['shop'], 'targets' => 1, 'skipped' => []])
        ->and($project->health_url)->toBe('https://shop.example.dev/up')
        ->and($project->image_templates)->toEqual([['var' => 'API_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/{owner}/{repo}', 'tag' => '{sha}']])
        ->and($project->credential_id)->toBe($credential->id)
        // CI is asked again; auto-deploy stays with the machine that had it.
        ->and($project->ci_state)->toBeNull()
        ->and($project->auto_deploy)->toBeFalse()
        ->and($project->envFiles()->sole()->content)->toBe("APP_KEY=base64:secret\nDB_PASSWORD=hunter2\n")
        ->and($project->envFiles()->sole()->version)->toBe(1)
        ->and($project->envFiles()->sole()->key_count)->toBe(2)
        ->and($project->hooks()->sole()->name)->toBe('Warm cache')
        ->and($project->actions()->sole()->definition()?->name())->toBe('Clear caches')
        ->and($imported->path)->toBe($path)
        ->and($imported->onServer()->ssh_alias)->toBe('box')
        ->and($imported->live()?->name)->toBe('20261018-100000')
        ->and($imported->live()?->color)->toBe('green')
        ->and($imported->previousRelease?->name)->toBe('20261017-100000')
        ->and($imported->previousRelease?->canRollBackTo($project))->toBeTrue()
        ->and($imported->releases()->count())->toBe(2)
        ->and($imported->bootstrapped_at)->not->toBeNull();

    Queue::assertPushed(PollCiStatus::class);
    expect(AuditEntry::query()->where('action', AuditAction::ProjectsImported)->sole()->subject)->toBe('1 project, 1 target');
});

it('keeps auto-deploy on only when asked to', function () {
    transferProject();
    $payload = app(ExportProjects::class)();
    forgetProjects();

    app(ImportProjects::class)($payload, keepAutoDeploy: true);

    expect(Project::query()->where('slug', 'shop')->sole()->auto_deploy)->toBeTrue();
});

it('leaves a project that is already here alone, and adds only the targets it lacks', function () {
    $target = transferProject();
    $other = Server::factory()->create(['ssh_alias' => 'edge', 'name' => 'Edge']);
    Target::factory()->create(['project_id' => $target->project_id, 'server_id' => $other->id, 'base_dir' => '/srv', 'path' => '/srv/shop']);
    $payload = app(ExportProjects::class)();

    // Here: the project, with its target on box but not the one on edge.
    $target->ofProject()->targets()->where('server_id', $other->id)->delete();
    $target->ofProject()->update(['health_url' => 'https://changed.example.dev/up']);

    $plan = app(ImportProjects::class)->plan($payload);

    expect($plan[0]['state'])->toBe('here')
        ->and(array_column($plan[0]['targets'], 'state', 'server'))->toBe(['box' => 'here', 'edge' => 'add']);

    $result = app(ImportProjects::class)($payload);

    expect($result)->toBe(['projects' => [], 'targets' => 1, 'skipped' => []])
        ->and(Project::query()->count())->toBe(1)
        ->and($target->ofProject()->fresh()->health_url)->toBe('https://changed.example.dev/up')
        ->and($target->ofProject()->targets()->where('server_id', $other->id)->sole()->path)->toBe('/srv/shop');
});

it('says which server is missing, and skips that target', function () {
    $target = transferProject();
    $payload = app(ExportProjects::class)();
    forgetProjects();
    $target->onServer()->update(['ssh_alias' => 'renamed']);

    $plan = app(ImportProjects::class)->plan($payload);

    expect($plan[0]['state'])->toBe('new')
        ->and($plan[0]['targets'][0]['state'])->toBe('no_server')
        ->and($plan[0]['targets'][0]['reason'])->toContain('No server with the ssh alias box here');

    $result = app(ImportProjects::class)($payload);

    expect($result['projects'])->toBe(['shop'])
        ->and($result['targets'])->toBe(0)
        ->and($result['skipped'][0])->toStartWith('Shop: ')
        ->and(Target::query()->count())->toBe(0);
});

it('says when no GitHub token here reaches the owner', function () {
    transferProject();
    $payload = app(ExportProjects::class)();
    forgetProjects();

    expect(app(ImportProjects::class)->plan($payload)[0]['notes'])->toContain('No GitHub token here reaches KielD-01: add one in Settings → GitHub.');
});

it('asks for a passkey before the transfer tab shows anything', function () {
    transferProject();

    Livewire::test(Index::class, ['tab' => 'transfer'])->assertSee('Confirm with a passkey');

    Livewire::test(Transfer::class)
        ->assertDontSee('Make the code')
        ->call('export')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('settings', ['tab' => 'transfer']));
});

it('exports and imports through the page, never keeping a passphrase', function () {
    session(['auth.password_confirmed_at' => time()]);
    transferProject();

    $code = Livewire::test(Transfer::class)
        ->assertSet('selected', ['shop'])
        ->set('passphrase', 'short')
        ->set('passphraseConfirmation', 'short')
        ->call('export')
        ->assertSee('at least 10 characters')
        ->assertSet('code', null)
        ->set('passphrase', 'correct horse battery')
        ->set('passphraseConfirmation', 'correct horse battery')
        ->call('export')
        ->assertSet('passphrase', '')
        ->assertSet('passphraseConfirmation', '')
        ->get('code');

    expect($code)->toStartWith('deployme1:')
        ->and(AuditEntry::query()->where('action', AuditAction::ProjectsExported)->sole()->detail)->toBe('shop');

    forgetProjects();

    Livewire::test(Transfer::class)
        ->set('importCode', $code)
        ->set('importPassphrase', 'nope nope nope')
        ->call('read')
        ->assertSee('Wrong passphrase')
        ->assertSet('plan', null)
        ->set('importPassphrase', 'correct horse battery')
        ->call('read')
        ->assertSet('importPassphrase', '')
        ->assertSet('importCode', '')
        ->assertSee('Shop')
        ->assertSee('Keep auto-deploy on for 1 project')
        ->assertSee('Import 1 project and 1 target')
        ->call('import')
        ->assertSee('Imported 1 project and 1 target.')
        ->assertSet('ticket', null);

    expect(Project::query()->where('slug', 'shop')->sole()->targets()->count())->toBe(1);
});

it('asks for the code again once the preview is old', function () {
    session(['auth.password_confirmed_at' => time()]);
    transferProject();
    $code = TransferCode::seal(app(ExportProjects::class)(), 'correct horse battery');
    forgetProjects();

    $page = Livewire::test(Transfer::class)
        ->set('importCode', $code)
        ->set('importPassphrase', 'correct horse battery')
        ->call('read');

    cache()->flush();

    $page->call('import')->assertSee('read too long ago');
    expect(Project::query()->count())->toBe(0);
});
