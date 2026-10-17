<?php

use App\Actions\Projects\ImportEnvFromServer;
use App\Enums\EnvFileRole;
use App\Livewire\Projects\Environment;
use App\Models\ProjectEnvFile;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

// A project folder on the "server": the real .env in shared/, the release
// holding a symlink to it, and an older copy left in the folder itself.
beforeEach(function () {
    $this->tmp = tempProject([
        'shared/.env' => "APP_KEY=base64:live\nDB_DATABASE=demo\n",
        'releases/20260912-120000/src/api/.env' => "APP_ENV=production\n",
        '.env' => "APP_KEY=base64:before-the-conversion\n",
    ]);
    symlink('releases/20260912-120000', "{$this->tmp}/current");
    symlink('../shared/.env', "{$this->tmp}/releases/20260912-120000/.env");
});

/**
 * @param  array<string, string>  $vars
 */
function readEnvScript(string $tmp, array $vars = []): ProcessResult
{
    $script = RemoteScript::file('deploy-read-env', ['TARGET' => $tmp, 'PATHS' => ".env\nsrc/api/.env", ...$vars]);

    return Process::input($script->render())->run(['bash', '-s']);
}

function importedFile(ProcessResult $run, string $path): string
{
    preg_match('/^::deployme::file='.preg_quote($path, '/').':(\S+)$/m', $run->output(), $match);

    return (string) base64_decode($match[1] ?? '', true);
}

it('reads the file the deployed release actually uses, not the one left beside it', function () {
    $run = readEnvScript($this->tmp);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('✓ .env (37 bytes, from shared/)')
        ->toContain('✓ src/api/.env (19 bytes, from current/)')
        ->toContain('::deployme::found=2')
        ->and(importedFile($run, '.env'))->toBe("APP_KEY=base64:live\nDB_DATABASE=demo\n")
        ->and(importedFile($run, 'src/api/.env'))->toBe("APP_ENV=production\n");
});

it('refuses a path that climbs out of the folder, and skips what isn’t there', function () {
    $run = readEnvScript($this->tmp, ['PATHS' => "../secrets/.env\n/etc/passwd\nsrc/web/.env"]);

    expect($run->output())->toContain("! ../secrets/.env isn't a path inside the project")
        ->toContain("! /etc/passwd isn't a path inside the project")
        ->toContain('::deployme::found=0')
        ->not->toContain('::deployme::file=');
});

it('leaves a file too big to be an env file alone', function () {
    file_put_contents("{$this->tmp}/shared/.env", str_repeat("A=1\n", 20000));

    expect(readEnvScript($this->tmp)->output())
        ->toContain('! .env is 80000 bytes, more than an env file should be')
        ->not->toContain('::deployme::file=.env:');
});

it('says so when the folder isn’t on the server', function () {
    $run = readEnvScript("{$this->tmp}/nowhere");

    expect($run->exitCode())->toBe(3)
        ->and($run->output())->toContain("isn't on the server");
});

it('stores what it read, encrypted and versioned like a file typed in', function () {
    $target = demoTarget(['dockerized' => true, 'detection' => ['compose' => [
        ['file' => 'docker-compose.prod.yml', 'env_files' => ['./.env', 'src/api/.env']],
    ]]]);
    $ssh = (new FakeSshClient)->on('box', 'deploy-read-env', fn ($alias, $script) => FakeSshClient::ok(
        '::deployme::file=.env:'.base64_encode("APP_KEY=base64:live\r\nDB_DATABASE=demo\n")."\n✓ .env\n::deployme::found=1\n",
    ));

    $result = (new ImportEnvFromServer($ssh))($target);

    $file = $target->project->envFiles()->sole();

    expect($result['imported'])->toBe(['.env'])
        ->and($file->content)->toBe("APP_KEY=base64:live\nDB_DATABASE=demo\n")
        ->and($file->role)->toBe(EnvFileRole::Compose)
        ->and($file->key_count)->toBe(2)
        ->and($file->getRawOriginal('content'))->not->toContain('base64:live')
        // The paths asked for come from the compose file, and the folder's own .env.
        ->and(scriptVar($ssh->calls[0]['script'], 'PATHS'))->toBe(".env\nsrc/api/.env")
        ->and(scriptVar($ssh->calls[0]['script'], 'TARGET'))->toBe($target->path);
});

it('keeps a second import that brings nothing new as one version', function () {
    $target = demoTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => EnvFileRole::App,
        ...ProjectEnvFile::contentAttributes("APP_KEY=base64:live\n")]);
    $ssh = (new FakeSshClient)->on('*', 'deploy-read-env', FakeSshClient::ok(
        '::deployme::file=.env:'.base64_encode("APP_KEY=base64:live\n")."\n::deployme::found=1\n",
    ));

    $result = (new ImportEnvFromServer($ssh))($target);

    expect($result)->toMatchArray(['imported' => [], 'unchanged' => ['.env']])
        ->and($target->project->envFiles()->sole()->version)->toBe(1);
});

it('imports from the Environment tab once you confirm it’s you', function () {
    $target = demoTarget();
    $target->update(['bootstrapped_at' => now()]);
    $this->app->instance(SshClient::class, (new FakeSshClient)->on('*', 'deploy-read-env', FakeSshClient::ok(
        '::deployme::file=.env:'.base64_encode("APP_KEY=base64:live\nDB_DATABASE=demo\n")."\n::deployme::found=1\n",
    )));

    $page = Livewire::test(Environment::class, ['projectId' => $target->project_id])
        ->assertSee('Import from')
        ->call('importFromServer')
        ->assertRedirect(route('password.confirm'));

    expect($target->project->envFiles()->count())->toBe(0);

    session(['auth.password_confirmed_at' => time()]);

    $page->call('importFromServer')
        ->assertSee("Imported .env from {$target->server->name}.")
        // A value is still masked until it's revealed, import or not.
        ->assertSee('DB_DATABASE')
        ->assertDontSee('base64:live');

    expect($target->project->envFiles()->sole()->path)->toBe('.env');
});

it('offers no import until the project has been deployed somewhere', function () {
    $target = demoTarget();

    Livewire::test(Environment::class, ['projectId' => $target->project_id])
        ->assertDontSee('Import from')
        ->call('importFromServer')
        ->assertSee('Deploy this project to a server first');
});
