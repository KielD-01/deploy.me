<?php

use App\Support\DotEnv;
use App\Support\Names;

it('makes folder and stack names servers accept', function () {
    expect(Names::folder('example.dev'))->toBe('example.dev')
        ->and(Names::folder('My App!! (v2)'))->toBe('my-app-v2')
        ->and(Names::folder('..hidden--dir'))->toBe('hidden-dir')
        ->and(Names::stack('example.dev'))->toBe('example-dev')
        ->and(Names::stack('Mr Studio'))->toBe('mr-studio')
        ->and(Names::isFolder('../etc'))->toBeFalse()
        ->and(Names::isFolder('a..b'))->toBeFalse()
        ->and(Names::isFolder('app.v2'))->toBeTrue()
        ->and(Names::isStack('app.v2'))->toBeFalse();
});

it('reads env keys the way a deploy target will see them', function () {
    $env = "# comment\nAPP_NAME=\"Deploy me\"\nexport APP_ENV=production\nEMPTY=\nQUOTED='a b' # note\nPLAIN=value # inline\nnot a line\n";

    expect(DotEnv::parse($env))->toBe([
        'APP_NAME' => 'Deploy me',
        'APP_ENV' => 'production',
        'EMPTY' => '',
        'QUOTED' => 'a b',
        'PLAIN' => 'value',
    ]);
});

it('recognises secret-looking keys', function () {
    $keys = ['APP_KEY', 'DB_PASSWORD', 'OPENBAO_TOKEN', 'ROOT_DOMAIN', 'DB_USERNAME', 'STRIPE_SECRET', 'MAIL_FROM_ADDRESS', 'SENTRY_DSN'];

    expect(array_values(array_filter($keys, DotEnv::isSecret(...))))
        ->toBe(['APP_KEY', 'DB_PASSWORD', 'OPENBAO_TOKEN', 'STRIPE_SECRET', 'SENTRY_DSN']);
});

it('treats a URL that carries a password as a secret, whatever its key', function () {
    expect(DotEnv::isSecret('DATABASE_URL', 'postgres://app:hunter2@postgres:5432/app'))->toBeTrue()
        ->and(DotEnv::isSecret('REDIS_URL', 'redis://:hunter2@redis:6379/0'))->toBeTrue()
        ->and(DotEnv::isSecret('AMQP', ' amqp://guest:guest@rabbit// '))->toBeTrue()
        ->and(DotEnv::isSecret('APP_URL', 'https://example.dev'))->toBeFalse()
        ->and(DotEnv::isSecret('MAIL_URL', 'smtp://mailer@example.dev:25'))->toBeFalse()
        ->and(DotEnv::isSecret('CALLBACK', 'https://example.dev/login?next=a:b@c'))->toBeFalse();
});
