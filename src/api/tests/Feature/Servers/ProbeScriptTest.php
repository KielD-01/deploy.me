<?php

use App\Platform\Remote\ProbeResult;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshResult;
use Illuminate\Support\Facades\Process;

/**
 * probe-net.sh itself, with the root tools it calls standing in for the real
 * ones: two network namespaces, the ss output each sample finds, which process
 * holds which socket, and a clock that moves two seconds.
 */
function netSampler(string $tmp): string
{
    $script = RemoteScript::file('probe-net', shell: 'sh');

    return Process::env(['PATH' => "{$tmp}/bin:".getenv('PATH'), 'TMPDIR' => $tmp, 'STUB' => $tmp])
        ->input($script->render())
        ->run(['sh', '-s', '1'])
        ->output();
}

beforeEach(function () {
    $socket = fn (int $ino, string $counters) => "ESTAB 0 0 10.0.0.2:8000 10.0.0.9:5{$ino} ino:{$ino} sk:{$ino} <->\n\t cubic rto:204 rtt:0.05/0.02 {$counters} segs_out:10\n";

    $this->tmp = tempProject([
        // The host's namespace (pids 1 and 7) and a container's (pid 4242).
        'bin/stat' => "#!/bin/sh\nprintf '4026531840 /proc/1/ns/net\\n4026531840 /proc/7/ns/net\\n4026532001 /proc/4242/ns/net\\n'\n",
        'bin/nsenter' => "#!/bin/sh\nshift\nexec \"\$@\"\n",
        'bin/ss' => "#!/bin/sh\nn=\$(cat \"\$STUB/ss.count\" 2>/dev/null || echo 0); n=\$((n + 1)); echo \"\$n\" > \"\$STUB/ss.count\"\ncat \"\$STUB/ss.\$n\"\n",
        'bin/sleep' => "#!/bin/sh\nexit 0\n",
        'bin/cut' => "#!/bin/sh\nn=\$(cat \"\$STUB/up.count\" 2>/dev/null || echo 0); n=\$((n + 1)); echo \"\$n\" > \"\$STUB/up.count\"\nif [ \"\$n\" = 1 ]; then echo 100.00; else echo 102.00; fi\n",
        'bin/find' => "#!/bin/sh\ncat \"\$STUB/owners\"\n",
        // First sample: the host, then the container.
        'ss.1' => $socket(1001, 'bytes_sent:1000 bytes_acked:1000 bytes_received:500').$socket(1002, 'bytes_sent:900 bytes_acked:900 bytes_received:900'),
        'ss.2' => $socket(2001, 'bytes_sent:10000 bytes_acked:10000 bytes_received:20000'),
        // Second sample: an older kernel's counters without bytes_sent, a socket
        // replaced under the same inode, one opened inside the window, and one
        // whose process is gone.
        'ss.3' => $socket(1001, 'bytes_acked:3000 bytes_received:1500').$socket(1002, 'bytes_sent:100 bytes_acked:100 bytes_received:100'),
        'ss.4' => $socket(2001, 'bytes_sent:50000 bytes_acked:50000 bytes_received:60000').$socket(2002, 'bytes_sent:4000 bytes_acked:4000 bytes_received:0')
            .$socket(2003, 'bytes_sent:999999 bytes_acked:999999 bytes_received:0'),
        // 4243 shares 2001 after a fork: the first holder found counts it.
        'owners' => "/proc/1/fd socket:[1001]\n/proc/1/fd socket:[1002]\n/proc/4242/fd socket:[2001]\n/proc/4242/fd socket:[2002]\n/proc/4243/fd socket:[2001]\n",
    ]);

    foreach (glob("{$this->tmp}/bin/*") as $stub) {
        chmod($stub, 0755);
    }
});

it('adds up each process’s TCP traffic across its sockets and namespaces, per second', function () {
    $lines = explode("\n", trim(netSampler($this->tmp)));

    expect($lines)->toHaveCount(3)
        // 4242: 40 000 + 4 000 sent and 40 000 received, over two seconds.
        ->and($lines[0])->toMatch('/^net 4242 22000\.0 20000\.0 \S+$/')
        // 1: 2 000 sent and 1 000 received; the replaced socket counts nothing.
        ->and($lines[1])->toMatch('/^net 1 1000\.0 500\.0 \S+$/')
        ->and($lines[2])->toBe('ok');
});

it('reports the busiest processes from a real /proc, and why the network list is missing', function () {
    $script = RemoteScript::file('probe', ['NET_SAMPLER' => (string) file_get_contents(resource_path('ssh-scripts/probe-net.sh'))], shell: 'sh');
    $run = Process::timeout(60)->input($script->render())->run(['sh', '-s']);
    $json = (new SshResult($run->exitCode(), $run->output(), $run->errorOutput(), 0))->between('__DEPLOYME_PROBE_BEGIN__', '__DEPLOYME_PROBE_END__');

    $top = ProbeResult::fromJson((string) $json)->processes();

    expect($top)->not->toBeNull()
        ->and($top['window'])->toBeGreaterThan(0.5)
        ->and($top['mem'])->not->toBeEmpty()
        ->and(count($top['mem']))->toBeLessThanOrEqual(10)
        ->and($top['mem'][0]['rss'])->toBeGreaterThan(0)
        ->and(collect($top['mem'])->pluck('rss')->all())->toBe(collect($top['mem'])->pluck('rss')->sortDesc()->values()->all());

    if (Process::run(['sh', '-c', 'command -v ss'])->failed()) {
        expect($top['net'])->toBe([])
            ->and($top['net_note'])->toBe("ss isn't installed (iproute2)");
    }
})->skip(! is_dir('/proc/self'), 'needs a Linux /proc');

it('samples the network only once the CPU second is over, so its work isn’t counted', function () {
    $tmp = tempProject([
        'bin/sleep' => "#!/bin/sh\necho sleep >> \"\$STUB/order\"\n",
        'bin/id' => "#!/bin/sh\ncase \"\$1\" in -u) echo 0 ;; *) echo root ;; esac\n",
        'bin/ss' => "#!/bin/sh\nexit 0\n",
        'bin/nsenter' => "#!/bin/sh\nexit 0\n",
    ]);
    foreach (glob("{$tmp}/bin/*") as $stub) {
        chmod($stub, 0755);
    }

    $script = RemoteScript::file('probe', ['NET_SAMPLER' => "echo net >> \"\$STUB/order\"\necho ok\n"], shell: 'sh');
    $run = Process::env(['PATH' => "{$tmp}/bin:".getenv('PATH'), 'STUB' => $tmp])
        ->timeout(60)->input($script->render())->run(['sh', '-s']);
    $json = (new SshResult($run->exitCode(), $run->output(), $run->errorOutput(), 0))->between('__DEPLOYME_PROBE_BEGIN__', '__DEPLOYME_PROBE_END__');

    expect(file($tmp.'/order', FILE_IGNORE_NEW_LINES))->toBe(['sleep', 'net'])
        ->and(ProbeResult::fromJson((string) $json)->processes()['net_note'])->toBeNull();
});
