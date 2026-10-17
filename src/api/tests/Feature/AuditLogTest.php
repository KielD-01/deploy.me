<?php

use App\Actions\Credentials\SaveGitHubToken;
use App\Actions\Maintenance\PruneOldData;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\DeploymentKind;
use App\Livewire\Audit\Index as AuditPage;
use App\Livewire\Projects\Show as ProjectPage;
use App\Livewire\Projects\Targets;
use App\Livewire\Servers\Show as ServerPage;
use App\Livewire\Settings\Index as SettingsPage;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\User;
use App\Platform\Ssh\AuditingSshClient;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\HostKeyScan;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['name' => 'Roman']);
    $this->actingAs($this->user);
});

it('refuses to let anything edit or delete what it recorded', function () {
    $entry = Audit::record(AuditAction::HostKeyTrusted, ['subject' => 'box']);

    // Each attempt gets a savepoint of its own, so the test's transaction survives it.
    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $entry->id)->update(['outcome' => 'failed'])))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $entry->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditEntry::query()->count())->toBe(1)
        ->and($entry->refresh()->outcome)->toBe(AuditOutcome::Ok);
});

it('records who saved and removed a GitHub token, with the hint and never the token', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'KielD-01', 'id' => 1, 'name' => 'Roman'], 200, ['X-OAuth-Scopes' => 'repo, read:packages']),
        'api.github.com/user/repos*' => Http::response([['owner' => ['login' => 'KielD-01']]]),
    ]);

    $credential = app(SaveGitHubToken::class)('ghp_'.str_repeat('a', 36));

    session(['auth.password_confirmed_at' => time()]);
    Livewire::test(SettingsPage::class)->set('tab', 'github')->call('removeToken', $credential->id);

    $entries = AuditEntry::query()->byHand()->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe([AuditAction::CredentialSaved, AuditAction::CredentialRemoved])
        ->and($entries->first()->actor)->toBe('Roman')
        ->and($entries->first()->subject)->toBe('GitHub token for KielD-01')
        // The hint, never the token itself.
        ->and($entries->first()->detail)->toContain($credential->token_hint)
        ->and(AuditEntry::query()->where('detail', 'like', '%'.$credential->token.'%')->exists())->toBeFalse();
});

it('records who trusted a host key, and which fingerprint', function () {
    config(['deployme.ssh.runtime_dir' => $dir = sys_get_temp_dir().'/deployme-audit-'.bin2hex(random_bytes(4))]);

    app(KnownHosts::class)->trust(new HostKeyScan(
        'box',
        ['198.51.100.7 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample'],
        [['type' => 'ED25519', 'fingerprint' => 'SHA256:MrYEXAMPLEfingerprint']],
    ), $this->user->id);

    expect(AuditEntry::query()->sole())
        ->action->toBe(AuditAction::HostKeyTrusted)
        ->actor->toBe('Roman')
        ->subject->toBe('box')
        ->detail->toBe('ED25519 SHA256:MrYEXAMPLEfingerprint');

    File::deleteDirectory($dir);
});

it('records a target, a project and a server being removed', function () {
    $target = demoTarget();
    $project = $target->project;
    $server = $target->server;

    Livewire::test(Targets::class, ['projectId' => $project->id])->call('remove', $target->id);
    Livewire::test(ProjectPage::class, ['project' => $project])->call('archive');
    Livewire::test(ServerPage::class, ['server' => $server])->call('archive');

    expect(AuditEntry::query()->byHand()->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::TargetRemoved, AuditAction::ProjectRemoved, AuditAction::ServerRemoved])
        ->and(AuditEntry::query()->where('action', AuditAction::ServerRemoved->value)->value('server_id'))->toBe($server->id)
        ->and(AuditEntry::query()->where('action', AuditAction::TargetRemoved->value)->value('project_id'))->toBe($project->id);
});

it('records the lock a run broke, with the person who started it', function () {
    $target = demoTarget();
    $ssh = fakeDeployScripts(new FakeSshClient);
    $ssh->on('box', 'deploy-unlock', FakeSshClient::ok("✓ Removed the lock\n::deployme::broken=1\n::deployme::previous=41\n"));
    $this->app->instance(SshClient::class, $ssh);

    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Unlock, 'user_id' => $this->user->id]);
    app(PipelineRunner::class)->run($run);

    $entry = AuditEntry::query()->where('action', AuditAction::LockBroken->value)->sole();

    expect($entry->actor)->toBe('Roman')
        ->and($entry->subject)->toBe('/opt/srv/demo on '.$target->server->name)
        ->and($entry->detail)->toBe('deploy #41 held it')
        ->and($entry->server_id)->toBe($target->server_id)
        ->and($entry->project_id)->toBe($target->project_id);
});

it('records every command run on a server, and how it ended, without the script’s variables', function () {
    $server = demoTarget()->server;
    $inner = new FakeSshClient;
    $inner->on('box', 'deploy-lease', FakeSshClient::ok("✓ Locked\n"));
    $inner->on('box', 'deploy-preflight', new SshResult(9, '', 'no', 1.5));
    $client = new AuditingSshClient($inner);

    $client->run('box', RemoteScript::file('deploy-lease', ['TOKEN' => 'super-secret']));
    $client->run('box', RemoteScript::file('deploy-preflight', []));

    $commands = AuditEntry::query()->where('action', AuditAction::RemoteCommand->value)->orderBy('id')->get();

    expect($commands->pluck('subject')->all())->toBe(['deploy-lease on box', 'deploy-preflight on box'])
        ->and($commands->pluck('outcome')->all())->toBe([AuditOutcome::Ok, AuditOutcome::Failed])
        ->and($commands->last()->detail)->toStartWith('exit 9')
        ->and($commands->first()->server_id)->toBe($server->id)
        ->and(AuditEntry::query()->where('detail', 'like', '%super-secret%')->exists())->toBeFalse();
});

it('leaves out the routine looks the scheduler takes when they succeed, and keeps the ones that fail', function () {
    auth()->forgetGuards();
    $inner = new FakeSshClient;
    $inner->on('box', 'probe', FakeSshClient::ok("fine\n"));
    $inner->on('box', 'deploy-health', new SshResult(1, '', 'down', 1.0));
    $inner->on('box', 'deploy-lease', FakeSshClient::ok("✓ Locked\n"));
    $client = new AuditingSshClient($inner);

    AuditingSshClient::scheduled(function () use ($client) {
        $client->run('box', RemoteScript::file('probe', []));
        $client->run('box', RemoteScript::file('deploy-health', ['URL' => 'https://x.test']));
        $client->run('box', RemoteScript::file('deploy-lease', []));
    });

    expect(AuditEntry::query()->orderBy('id')->pluck('outcome', 'subject')->all())
        ->toBe(['deploy-health on box' => AuditOutcome::Failed, 'deploy-lease on box' => AuditOutcome::Ok]);

    // Outside the scheduler's jobs (a deploy's health step, say) it is audited as before.
    $client->run('box', RemoteScript::file('probe', []));

    expect(AuditEntry::query()->where('subject', 'probe on box')->count())->toBe(1);
});

it('keeps a person’s probe in the log, even inside the scheduler’s jobs', function () {
    $inner = new FakeSshClient;
    $inner->on('box', 'probe', FakeSshClient::ok("fine\n"));
    $client = new AuditingSshClient($inner);

    AuditingSshClient::scheduled(fn () => $client->run('box', RemoteScript::file('probe', [])));

    expect(AuditEntry::query()->sole())->actor->toBe('Roman');
});

it('has a partial index for the by-hand listing', function () {
    expect(DB::selectOne("select indexdef from pg_indexes where indexname = 'audit_log_by_hand_index'")->indexdef)
        ->toContain("<> 'ssh.command'");
});

it('records a command that never reached the server', function () {
    $inner = new FakeSshClient;
    $inner->on('box', 'deploy-lease', new HostUnreachable('box is not answering.'));
    $client = new AuditingSshClient($inner);

    expect(fn () => $client->run('box', RemoteScript::file('deploy-lease', [])))->toThrow(HostUnreachable::class);

    expect(AuditEntry::query()->sole())
        ->action->toBe(AuditAction::RemoteCommand)
        ->outcome->toBe(AuditOutcome::Failed);
});

it('lists the log, filtered by server and by project, and links to it from both pages', function () {
    $target = demoTarget();
    Audit::record(AuditAction::HostKeyTrusted, ['subject' => 'box', 'server_id' => $target->server_id]);
    Audit::record(AuditAction::TargetRemoved, ['subject' => 'demo on box', 'server_id' => $target->server_id, 'project_id' => $target->project_id]);
    Audit::record(AuditAction::RemoteCommand, ['subject' => 'deploy-probe on box', 'server_id' => $target->server_id]);
    Audit::record(AuditAction::CredentialSaved, ['subject' => 'GitHub token for KielD-01']);

    Livewire::test(AuditPage::class)
        ->assertSee(['Trusted a host key', 'Removed a target', 'Ran a command on a server', 'Saved a GitHub token'])
        ->set('kind', 'by_hand')
        ->assertDontSee('Ran a command on a server')
        ->set('kind', 'all')
        ->set('project', (string) $target->project_id)
        ->assertSee('Removed a target')
        ->assertDontSee('Trusted a host key')
        ->set('project', '')
        ->set('server', (string) $target->server_id)
        ->assertSee('Trusted a host key')
        ->assertDontSee('Saved a GitHub token');

    // Both pages show what was done by hand, and link to the rest.
    $this->get(route('servers.show', $target->server))->assertOk()->assertSee('Trusted a host key')->assertSee(route('audit', ['server' => $target->server_id]));
    $this->get(route('projects.show', $target->project))->assertOk()->assertSee('Removed a target')->assertSee(route('audit', ['project' => $target->project_id]));
});

it('prunes the audit log past its retention, and nothing newer', function () {
    // Nothing can back-date a row afterwards, so record them when they happened.
    $this->travelTo(now()->subDays(400));
    Audit::record(AuditAction::HostKeyTrusted, ['subject' => 'box']);
    $this->travelTo(now()->addDays(340));
    $kept = Audit::record(AuditAction::HostKeyTrusted, ['subject' => 'box2']);
    $this->travelBack();

    expect(app(PruneOldData::class)()['audit'])->toBe(1)
        ->and(AuditEntry::query()->pluck('id')->all())->toBe([$kept->id]);

    // 60 days old, and the log now keeps 30.
    Settings::set('audit_retention_days', 30);

    expect(app(PruneOldData::class)()['audit'])->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});
