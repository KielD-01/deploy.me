<?php

use App\Platform\Remote\DirectoryListing;
use App\Platform\Remote\ProbeResult;
use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use App\Support\Format;

it('parses a folder listing: folders first, links to folders, writability, filesystem', function () {
    $stdout = "__DEPLOYME_CWD__ /opt\n__DEPLOYME_CWD_WRITABLE__ 0\n__DEPLOYME_DF__ /dev/sda1 ext4 171798691840 104793882624 /\n__DEPLOYME_ENTRIES__\n"
        ."d\td\t755\troot\troot\t4096\t1694351234.5\tinfra\0"
        ."d\td\t775\tdeploy\tdeploy\t4096\t1694351234.5\tsrv\0"
        ."f\tf\t644\troot\troot\t12\t1694351234.5\tREADME\0"
        ."l\td\t777\troot\troot\t9\t1694351234.5\tlatest\0"
        ."d\td\t775\tdeploy\tdeploy\t4096\t1694351234.5\tmy app 'v2'\0"
        ."\n__DEPLOYME_WRITABLE__\nsrv\0my app 'v2'\0";

    $listing = DirectoryListing::parse($stdout, 1000);

    expect($listing->path)->toBe('/opt')
        ->and($listing->writable)->toBeFalse()
        ->and($listing->filesystem)->toMatchArray(['fstype' => 'ext4', 'mount' => '/', 'available' => 104793882624])
        ->and(array_map(fn ($entry) => $entry->name, $listing->entries))->toBe(['infra', 'latest', "my app 'v2'", 'srv', 'README'])
        ->and($listing->entries[1]->isDir)->toBeTrue()
        ->and($listing->entries[2]->writable)->toBeTrue()
        ->and($listing->entries[0]->writable)->toBeFalse();
});

it('reads specs, capabilities and readings from a probe', function () {
    $probe = ProbeResult::fromJson(file_get_contents(__DIR__.'/../../Fixtures/probe/ubuntu-24.04.json'));

    expect($probe->specs())->toMatchArray(['os' => 'Ubuntu 24.04.3 LTS', 'cores' => 4, 'threads' => 8, 'cpu_model' => 'AMD EPYC 7B13'])
        ->and($probe->capabilities())->toMatchArray(['docker' => '28.3.2', 'compose' => '2.39.1', 'gnu_mv' => true, 'sudo_nopasswd' => false, 'docker_group' => true, 'proxy_network' => true])
        ->and($probe->memoryUsedPercent())->toBe(43.1)
        ->and($probe->snapshot())->toMatchArray(['cpu_usage' => 12.3, 'load_1' => 0.42, 'uptime' => 3283200]);
});

it('finds the filesystem a path lives on', function () {
    $probe = ProbeResult::fromJson(file_get_contents(__DIR__.'/../../Fixtures/probe/ubuntu-24.04.json'));

    expect($probe->diskFor('/srv/apps')['mount'])->toBe('/srv')
        ->and($probe->diskFor('/opt/srv')['mount'])->toBe('/')
        ->and($probe->diskFor('/srvx')['mount'])->toBe('/')
        ->and(ProbeResult::usedPercent($probe->diskFor('/srv')))->toBe(95.0);
});

it('treats "-" as an unnamed CPU', function () {
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/probe/ubuntu-24.04.json'), true);
    $data['cpu']['model'] = '-';

    expect((new ProbeResult($data))->specs()['cpu_model'])->toBeNull();
});

it('rejects probe output it does not understand', function (string $json) {
    ProbeResult::fromJson($json);
})->with(['{"v":2}', 'not json'])->throws(RemoteCommandFailed::class);

it('formats bytes and uptime', function () {
    expect(Format::bytes(0))->toBe('0 B')
        ->and(Format::bytes(1536))->toBe('1.5 KB')
        ->and(Format::bytes(17179869184))->toBe('16 GB')
        ->and(Format::uptime(3283200))->toBe('38 days')
        ->and(Format::uptime(7200))->toBe('2 h');
});
