<?php

use App\Models\User;
use App\Support\EnvFile;

beforeEach(function () {
    // Point the command at a throwaway .env so the real one is never touched.
    $this->envPath = tempnam(sys_get_temp_dir(), 'deployme-env');
    file_put_contents($this->envPath, "REVERB_APP_ID=\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n");
    $this->app->instance(EnvFile::class, new EnvFile($this->envPath));
});

afterEach(fn () => @unlink($this->envPath));

it('generates Reverb credentials once', function () {
    $this->artisan('deployme:setup')->assertSuccessful();
    $key = (new EnvFile($this->envPath))->get('REVERB_APP_KEY');

    $this->artisan('deployme:setup')->assertSuccessful();

    expect($key)->toHaveLength(20)
        ->and((new EnvFile($this->envPath))->get('REVERB_APP_KEY'))->toBe($key)
        ->and((new EnvFile($this->envPath))->get('REVERB_APP_SECRET'))->toHaveLength(40);
});

it('creates the account and prints its enrollment link when nobody has one yet', function () {
    expect(User::query()->exists())->toBeFalse();

    $this->artisan('deployme:setup')
        ->expectsOutputToContain('/enroll/')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('prints no link when an account already exists', function () {
    User::factory()->create();

    $this->artisan('deployme:setup')->doesntExpectOutputToContain('/enroll/')->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});
