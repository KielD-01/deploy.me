<?php

use App\Deploy\Logging\SecretRedactor;

it('masks the password in any URL’s credentials, not only an https one', function (string $line, string $masked) {
    expect((new SecretRedactor)->redact($line))->toBe($masked);
})->with([
    'https token' => ['fatal: https://bot:ghp_x@github.com/o/r', 'fatal: https://github.com/o/r'],
    'postgres' => ['DATABASE_URL=postgres://app:s3cret@postgres:5432/app', 'DATABASE_URL=postgres://app:'.SecretRedactor::MASK.'@postgres:5432/app'],
    'redis' => ['connecting to redis://default:hunter22@redis:6379', 'connecting to redis://default:'.SecretRedactor::MASK.'@redis:6379'],
    'ssh user only' => ['ssh://git@github.com/o/r.git', 'ssh://git@github.com/o/r.git'],
]);
