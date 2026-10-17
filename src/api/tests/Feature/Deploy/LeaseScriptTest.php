<?php

// AcquireLease and ReleaseLease give their scripts 30 s over ssh. A wait for
// .deploy/'s flock as long as that ends as an ssh timeout (and a deploy-kill)
// instead of the script's own "busy" answer (exit 21).
it('waits for the folder’s flock for less time than ssh gives the script', function (string $script) {
    preg_match('/flock -w (\d+)/', (string) file_get_contents(resource_path("ssh-scripts/{$script}.sh")), $match);

    expect((int) ($match[1] ?? 0))->toBeGreaterThan(0)->toBeLessThanOrEqual(20);
})->with(['deploy-lease', 'deploy-unlock']);
