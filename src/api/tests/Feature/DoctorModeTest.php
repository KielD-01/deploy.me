<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // The parts of doctor this test isn't about: the broadcast round trip and
    // the scheduler's heartbeat.
    Broadcast::shouldReceive('connection')->andReturnSelf();
    Broadcast::shouldReceive('broadcast')->andReturnNull();
    Cache::put('deployme:scheduler:heartbeat', now()->getTimestamp());

    $this->dir = sys_get_temp_dir().'/deployme-doctor-mode-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir.'/views');
    config(['view.compiled' => $this->dir.'/views']);

    $this->timestamps = ini_get('opcache.validate_timestamps');
});

afterEach(function () {
    ini_set('opcache.validate_timestamps', (string) $this->timestamps);
    File::deleteDirectory($this->dir);
});

it('is happy with debug on in development mode, and checks none of production’s settings', function () {
    config(['deployme.mode' => 'dev', 'app.debug' => true]);

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('Development mode: the code is read live from src/api')
        ->doesntExpectOutputToContain('APP_DEBUG')
        ->doesntExpectOutputToContain('cached')
        ->doesntExpectOutputToContain('OPcache');
});

it('fails production mode that runs with debug on, uncached and re-checking timestamps', function () {
    config(['deployme.mode' => 'prod', 'app.debug' => true]);
    ini_set('opcache.validate_timestamps', '1');
    foreach (['config_loaded_from_cache', 'routes.cached', 'events.cached'] as $binding) {
        app()->instance($binding, false);
    }

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('Production mode')
        ->expectsOutputToContain('✗ APP_DEBUG is off — APP_DEBUG=true in src/api/.env shows a stack trace')
        ->expectsOutputToContain('✗ APP_ENV is production — APP_ENV is testing')
        ->expectsOutputToContain('✗ Config, routes and events are cached — not cached: config, routes, events')
        ->expectsOutputToContain('✗ Views are compiled')
        ->expectsOutputToContain('✗ OPcache doesn’t re-check file timestamps')
        ->assertFailed();
});

it('passes production mode with debug off, every cache built and OPcache trusting the code', function () {
    config(['deployme.mode' => 'prod', 'app.debug' => false]);
    app()->detectEnvironment(fn () => 'production');
    ini_set('opcache.validate_timestamps', '0');

    // What the app records when it boots from its caches (it asks once, then
    // remembers): no real cache file is written for a test.
    app()->instance('config_loaded_from_cache', true);
    app()->instance('routes.cached', true);
    app()->instance('events.cached', true);
    file_put_contents("{$this->dir}/views/0123abcd.php", "<?php\n");

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('✓ APP_DEBUG is off')
        ->expectsOutputToContain('✓ APP_ENV is production')
        ->expectsOutputToContain('✓ Config, routes and events are cached')
        ->expectsOutputToContain('✓ Views are compiled')
        ->expectsOutputToContain('✓ OPcache doesn’t re-check file timestamps');
});

it('names the one cache that is missing', function () {
    config(['deployme.mode' => 'prod', 'app.debug' => false]);

    app()->instance('config_loaded_from_cache', true);
    app()->instance('routes.cached', false);
    app()->instance('events.cached', true);

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('✗ Config, routes and events are cached — not cached: routes (')
        ->assertFailed();
});
