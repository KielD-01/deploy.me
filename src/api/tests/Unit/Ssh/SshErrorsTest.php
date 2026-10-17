<?php

use App\Platform\Ssh\Exceptions\AuthenticationFailed;
use App\Platform\Ssh\Exceptions\HostKeyChanged;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use App\Platform\Ssh\Exceptions\SshConfigError;
use App\Platform\Ssh\LineBuffer;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;

it('turns ssh failures into typed exceptions', function (string $stderr, string $class) {
    expect(SshErrors::from(new SshResult(255, '', $stderr, 0)))->toBeInstanceOf($class);
})->with([
    'unknown key' => ["No ED25519 host key is known for sandbox and you have requested strict checking.\nHost key verification failed.", HostKeyUnknown::class],
    'changed key' => ["@@@@@@@@\n@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\nHost key verification failed.", HostKeyChanged::class],
    'refused key' => ['deploy@203.0.113.10: Permission denied (publickey).', AuthenticationFailed::class],
    'refused port' => ['ssh: connect to host 203.0.113.10 port 22: Connection refused', HostUnreachable::class],
    'no dns' => ['ssh: Could not resolve hostname nowhere: Name or service not known', HostUnreachable::class],
    'timeout' => ['ssh: connect to host 203.0.113.10 port 22: Connection timed out', HostUnreachable::class],
    'bad config' => ['/run/deployme/ssh/config line 3: Bad configuration option: usekeychain', SshConfigError::class],
]);

it('treats any other exit code as the remote command failing', function () {
    expect(SshErrors::from(new SshResult(3, 'partial output', 'oops', 0)))->toBeInstanceOf(RemoteCommandFailed::class);
});

it('splits output into lines across chunks and carriage returns', function () {
    $buffer = new LineBuffer;

    expect($buffer->push('out', "first\nsec"))->toBe([['out', 'first']])
        ->and($buffer->push('out', "ond\rprogress 50%\rprogress 100%\n"))->toBe([['out', 'second'], ['out', 'progress 50%'], ['out', 'progress 100%']])
        ->and($buffer->push('err', 'no newline yet'))->toBe([])
        ->and($buffer->flush())->toBe([['err', 'no newline yet']]);
});
