<?php

namespace App\Http\Controllers;

use App\Deploy\Logging\LogArchive;
use App\Models\Deployment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A run's whole log as a text file, streamed in pages from the database, or
 * from its archive once the lines have left the database.
 */
class DeploymentLogController extends Controller
{
    private const PAGE = 5000;

    public function __invoke(Deployment $deployment): StreamedResponse
    {
        return response()->streamDownload(function () use ($deployment) {
            $seq = 0;
            $any = false;

            do {
                $lines = $deployment->logLines($seq, self::PAGE);

                foreach ($lines as $line) {
                    echo LogArchive::format($line)."\n";
                    $seq = (int) $line->seq;
                    $any = true;
                }
            } while ($lines->count() === self::PAGE);

            if (! $any && LogArchive::exists($deployment->id)) {
                LogArchive::stream($deployment->id);
            }
        }, "deploy-{$deployment->id}.log", ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
