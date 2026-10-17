<?php

use App\Deploy\ReleasePruner;
use App\Enums\ReleaseStatus;
use App\Models\Release;

function prunerRelease(int $id, string $name, ReleaseStatus $status = ReleaseStatus::Ready): Release
{
    return (new Release)->forceFill(['id' => $id, 'name' => $name, 'status' => $status]);
}

function prunedIds(array $releases): array
{
    return array_map(fn (Release $release) => $release->id, $releases);
}

it('keeps the newest releases, counting the live one and the one it replaced', function () {
    $live = prunerRelease(10, '20260911-100000');
    $previous = prunerRelease(9, '20260910-100000');
    $onDisk = collect([
        $previous,
        prunerRelease(8, '20260909-100000'),
        prunerRelease(7, '20260908-100000', ReleaseStatus::Failed),
        prunerRelease(6, '20260907-100000'),
    ]);

    expect(prunedIds(ReleasePruner::select($onDisk, $live, $previous, 3)))->toBe([7, 6])
        ->and(prunedIds(ReleasePruner::select($onDisk, $live, $previous, 10)))->toBe([7])
        // Keeping one means no rollback: even the one it replaced goes.
        ->and(prunedIds(ReleasePruner::select($onDisk, $live, $previous, 1)))->toBe([9, 8, 7, 6]);
});

it('keeps the release a rollback would switch to, even when it isn’t the newest', function () {
    $live = prunerRelease(10, '20260911-100000');
    $previous = prunerRelease(5, '20260901-100000');
    $onDisk = collect([prunerRelease(9, '20260910-100000'), prunerRelease(8, '20260909-100000'), $previous]);

    expect(prunedIds(ReleasePruner::select($onDisk, $live, $previous, 2)))->toBe([9, 8]);
});
