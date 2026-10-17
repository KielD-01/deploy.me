<?php

use App\Actions\Maintenance\CheckForUpdate;
use App\Enums\DeploymentStatus;
use App\Enums\SyncRunStatus;
use App\Livewire\Settings\Index;
use App\Models\Deployment;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Settings;
use App\Support\Updates;
use App\Terminal\Shell;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;

beforeEach(function () {
    // CI builds no assets: a page asks Vite for a manifest that isn't there.
    $this->withoutVite();
    Http::preventStrayRequests();
    config([
        'deployme.version' => '1.0.1',
        'deployme.update.repository' => 'acme/deploy.me',
        'deployme.update.state_file' => storage_path('framework/testing/update-state-'.uniqid().'.json'),
        'deployme.update.agent_file' => storage_path('framework/testing/update-agent-'.uniqid()),
    ]);
});

/**
 * What GitHub answers, one answer per request (a stub registered first would win over a later one).
 */
function githubSays(PromiseInterface|Response ...$answers): void
{
    $sequence = Http::sequence();
    foreach ($answers as $answer) {
        $sequence->pushResponse($answer);
    }
    Http::fake(['api.github.com/repos/acme/deploy.me/releases/latest' => $sequence]);
}

function release(string $tag = 'v1.0.2', array $extra = []): Response|PromiseInterface
{
    return Http::response([
        'tag_name' => $tag,
        'name' => "deploy.me {$tag}",
        'body' => "Fixes.\n<script>alert(1)</script>",
        'html_url' => "https://github.com/acme/deploy.me/releases/tag/{$tag}",
        'published_at' => '2026-10-17T10:00:00Z',
        ...$extra,
    ]);
}

function releaseOnGitHub(string $tag = 'v1.0.2', array $extra = []): void
{
    githubSays(release($tag, $extra), release($tag, $extra), release($tag, $extra));
}

// --- the check ---------------------------------------------------------------------------

it('keeps the newest release GitHub names, and says a newer one is out', function () {
    releaseOnGitHub('v1.0.2');

    $result = app(CheckForUpdate::class)();

    expect($result)->toBe(['ok' => true, 'message' => '1.0.2 is out (this is 1.0.1).'])
        ->and(Updates::latest()['version'])->toBe('1.0.2')
        ->and(Updates::latest()['tag'])->toBe('v1.0.2')
        ->and(Updates::available())->toMatchArray(['version' => '1.0.2', 'kind' => 'patch']);
    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'deploy.me') && ! $request->hasHeader('Authorization'));
});

it('has nothing to tell when this is the newest release', function () {
    releaseOnGitHub('v1.0.1');

    app(CheckForUpdate::class)();

    expect(Updates::latest()['version'])->toBe('1.0.1')->and(Updates::available())->toBeNull();
});

it('does not look when the check is off, unless it is asked to', function () {
    Settings::set('update_check', 0);

    expect(app(CheckForUpdate::class)()['message'])->toBe('Checking for updates is off.');
    Http::assertNothingSent();

    releaseOnGitHub();
    app(CheckForUpdate::class)(force: true);
    expect(Updates::latest())->not->toBeNull();
});

it('keeps the last answer when GitHub does not answer, and notes why', function () {
    githubSays(release('v1.0.2'), Http::response('rate limited', 403));
    app(CheckForUpdate::class)();

    $result = app(CheckForUpdate::class)();

    expect($result['ok'])->toBeFalse()
        ->and(Updates::latest()['version'])->toBe('1.0.2')
        ->and(Updates::latest()['error'])->not->toBeNull();
});

it('treats "no release yet" as an answer, not a failure', function () {
    githubSays(Http::response(['message' => 'Not Found'], 404));

    expect(app(CheckForUpdate::class)())->toBe(['ok' => true, 'message' => 'No release has been published yet.'])
        ->and(Updates::latest())->toBeNull();
});

it('refuses an answer that is not a release number or does not point at github.com', function (array $extra) {
    githubSays(release('v1.0.2'), release(...$extra));
    app(CheckForUpdate::class)();

    expect(app(CheckForUpdate::class)()['ok'])->toBeFalse()
        ->and(Updates::latest()['version'])->toBe('1.0.2');
})->with([
    'a tag that is no number' => [['nightly']],
    'a link elsewhere' => [['v9.9.9', ['html_url' => 'https://evil.example/release']]],
]);

it('is scheduled once a day', function () {
    $event = collect(Schedule::events())->first(fn ($event) => $event->description === 'check-for-update');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('43 6 * * *');
});

// --- what bin/update is told -------------------------------------------------------------

it('plans what the nightly run may apply', function (string $mode, string $latest, string $action) {
    releaseOnGitHub($latest);
    app(CheckForUpdate::class)();
    Updates::setMode($mode);

    expect(Updates::plan(auto: true)['action'])->toBe($action);
})->with([
    'off does nothing' => ['off', 'v1.0.2', 'none'],
    'patch takes a patch' => ['patch', 'v1.0.2', 'apply'],
    'patch leaves a minor' => ['patch', 'v1.1.0', 'none'],
    'all takes a minor' => ['all', 'v1.1.0', 'apply'],
    'all takes a major' => ['all', 'v2.0.0', 'apply'],
]);

it('lets a person apply any release, whatever the nightly mode', function () {
    releaseOnGitHub('v2.0.0');
    app(CheckForUpdate::class)();

    expect(Updates::mode())->toBe('off')
        ->and(Updates::plan(auto: false)['action'])->toBe('apply');
});

it('waits while a deploy or a folder sync runs', function () {
    releaseOnGitHub();
    app(CheckForUpdate::class)();
    Updates::setMode('all');

    expect(Updates::busy())->toBeFalse();

    $run = Deployment::factory()->create(['status' => DeploymentStatus::Running]);
    expect(Updates::busy())->toBeTrue()->and(Updates::plan(auto: true)['action'])->toBe('busy');

    $run->update(['status' => DeploymentStatus::Succeeded]);
    expect(Updates::busy())->toBeFalse();

    SyncRun::factory()->create(['status' => SyncRunStatus::Running]);
    expect(Updates::busy())->toBeTrue();
});

it('answers bin/update in exit codes: 0 apply, 3 busy, 4 nothing to do', function () {
    $this->artisan('deployme:update', ['action' => 'plan', '--auto' => true])->assertExitCode(4);

    releaseOnGitHub();
    app(CheckForUpdate::class)();
    $this->artisan('deployme:update', ['action' => 'plan'])->assertExitCode(0);
    $this->artisan('deployme:update', ['action' => 'plan', '--auto' => true])->assertExitCode(4);

    Deployment::factory()->create(['status' => DeploymentStatus::Queued]);
    $this->artisan('deployme:update', ['action' => 'plan'])->assertExitCode(3);
    $this->artisan('deployme:update', ['action' => 'busy'])->assertExitCode(3);
});

it('says busy only while something runs, even before any check', function () {
    $this->artisan('deployme:update', ['action' => 'busy'])->assertExitCode(0);
});

it('keeps and reads the nightly mode, and refuses one that does not exist', function () {
    $this->artisan('deployme:update', ['action' => 'mode'])->expectsOutput('off')->assertExitCode(0);
    $this->artisan('deployme:update', ['action' => 'mode', 'value' => 'patch'])->assertExitCode(0);
    expect(Updates::mode())->toBe('patch');
    $this->artisan('deployme:update', ['action' => 'mode', 'value' => 'sometimes'])->assertExitCode(2);
    expect(Updates::mode())->toBe('patch');
});

it('reads what bin/update wrote about its last run', function () {
    expect(Updates::lastRun())->toBeNull();

    file_put_contents(config('deployme.update.state_file'), json_encode([
        'at' => '2026-10-17T19:31:30Z', 'result' => 'rolled_back', 'from' => '1.0.1', 'to' => '1.1.0',
        'message' => 'Failed its checks.', 'backup' => '/home/me/Backups/deployme/deployme-1.sql.gz',
    ]));

    expect(Updates::lastRun())->toMatchArray(['result' => 'rolled_back', 'from' => '1.0.1', 'to' => '1.1.0']);

    file_put_contents(config('deployme.update.state_file'), '{not json');
    expect(Updates::lastRun())->toBeNull();
});

// --- Settings → Updates and the hints -------------------------------------------------------

it('shows the new release on Settings → Updates, its notes as text', function () {
    $this->actingAs(User::factory()->create());
    releaseOnGitHub('v1.1.0');
    app(CheckForUpdate::class)();

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->assertSee(['deploy.me 1.0.1', '1.1.0 is out', 'a minor release', 'bin/update', 'Release notes on GitHub'])
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('checks now from the button, and says when GitHub did not answer', function () {
    $this->actingAs(User::factory()->create());
    githubSays(release('v1.0.2'), Http::response('', 500));

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->call('checkForUpdate')
        ->assertSee('1.0.2 is out');

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->call('checkForUpdate')
        ->assertSee('GitHub didn’t answer.');
});

it('asks for a passkey before turning on updates that apply themselves', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->set('autoUpdate', 'patch')
        ->call('saveUpdates')
        ->assertRedirect(route('password.confirm'));
    expect(Updates::mode())->toBe('off');

    session(['auth.password_confirmed_at' => time()]);
    Livewire::test(Index::class, ['tab' => 'updates'])
        ->set('autoUpdate', 'patch')
        ->set('updateCheck', 0)
        ->call('saveUpdates')
        ->assertSee('Update settings saved.');

    expect(Updates::mode())->toBe('patch')->and(Settings::int('update_check'))->toBe(0);
});

it('lets the nightly mode go back to off without a passkey', function () {
    $this->actingAs(User::factory()->create());
    Updates::setMode('all');

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->set('autoUpdate', 'off')
        ->call('saveUpdates')
        ->assertSee('Update settings saved.');

    expect(Updates::mode())->toBe('off');
});

it('says the nightly job is missing when a mode is picked and bin/update auto was never run', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class, ['tab' => 'updates'])
        ->set('autoUpdate', 'patch')
        ->assertSee('bin/update auto patch');

    touch(config('deployme.update.agent_file'));
    Livewire::test(Index::class, ['tab' => 'updates'])
        ->set('autoUpdate', 'patch')
        ->assertDontSee('isn’t installed on this machine yet');
});

it('points at an update from the console status bar, the classic menu and the status command', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    releaseOnGitHub('v1.0.2');
    app(CheckForUpdate::class)();
    demoTarget();

    $this->get(route('dashboard'))->assertSee('↑ 1.0.2', false);
    $status = collect(app(Shell::class)->run('status')->lines)
        ->map(fn (array $line) => collect($line)->map(fn (array $segment) => $segment[0])->join(''))->join("\n");
    expect($status)->toContain('deploy.me 1.0.2 is out (running 1.0.1)', 'bin/update');

    $user->forceFill(['ui' => ['theme' => 'classic']])->save();
    $this->get(route('servers.index'))->assertSee('deploy.me 1.0.2 is out');
});

it('shows nothing about updates while none is out', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertDontSee('is out')->assertDontSee('↑ 1.0');
});
