<?php

use App\Platform\Ssh\RemoteScript;
use Illuminate\Support\Facades\Process;

// deploy-link-shared.sh run with bash in a folder of the worker's own.

it('leaves shared/ alone for a shared folder inside another one', function () {
    $tmp = tempProject([
        'shared/storage/app/public/avatar.png' => 'uploaded',
        'shared/storage/logs/app.log' => 'kept',
        'shared/config/.env' => 'SECRET=1',
        'releases/R/storage/app/public/.gitkeep' => '',
        'releases/R/config/app.php' => '<?php',
    ]);
    $script = RemoteScript::file('deploy-link-shared', [
        'RELEASE' => "{$tmp}/releases/R", 'SHARED' => "{$tmp}/shared",
        // Both orders: the inner one first, then the outer one.
        'DIRS' => "storage\nstorage/app/public\nconfig/cache\nconfig",
        'FILES' => 'config/.env',
    ]);

    $run = Process::input($script->render())->run(['bash', '-s']);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and(is_link("{$tmp}/shared/storage/app/public"))->toBeFalse()
        ->and(@file_get_contents("{$tmp}/shared/storage/app/public/avatar.png"))->toBe('uploaded')
        ->and(@file_get_contents("{$tmp}/releases/R/storage/app/public/avatar.png"))->toBe('uploaded')
        ->and(is_link("{$tmp}/shared/config/.env"))->toBeFalse()
        ->and(@file_get_contents("{$tmp}/releases/R/config/.env"))->toBe('SECRET=1')
        ->and(is_link("{$tmp}/releases/R/config"))->toBeTrue();
});
